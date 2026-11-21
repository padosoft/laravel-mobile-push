<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceRegistry;
use Padosoft\MobilePush\Laravel\Campaigns\Console\ConsoleCommands;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;
use Padosoft\MobilePush\Laravel\Integration\ModuleMigrations;
use Padosoft\MobilePush\Laravel\Module;
use Padosoft\MobilePush\Laravel\Queue\Events\RelayRunFinished;
use Padosoft\MobilePush\Laravel\Queue\QueueSettings;
use Padosoft\MobilePush\Laravel\Queue\SendJobDispatcher;
use Psr\Clock\ClockInterface;

/**
 * Campaigns: one message to a large audience, durable profile only.
 *
 * Bindings always exist and resolve lazily; the migration is loaded only when mobile-push.campaigns.enabled is true
 * AND mobile-push.delivery.profile is "durable" (creating a campaign otherwise throws a ConfigurationException that
 * says why). Publish tag: mobile-push-migrations-campaigns. SweepCampaignsJob is scheduled every minute when the
 * module is usable and mobile-push.campaigns.sweeper.schedule is true.
 *
 * Config (read with defaults, see CampaignSettings): mobile-push.campaigns.{enabled=false,
 * table=mobile_push_campaigns, connection=null, slice=1000, default_rate_per_minute=null, retention_days=30,
 * sweeper.schedule=true, audiences=[]}.
 */
final class CampaignsModule implements Module
{
    public const PUBLISH_TAG = 'mobile-push-migrations-campaigns';

    public const MIGRATION = '2026_11_20_000040_create_mobile_push_campaigns_table.php';

    public const SWEEP_EVENT = 'mobile-push:sweep-campaigns';

    public static function register(Application $app): void
    {
        // bind, not singleton: settings derived from config are re-read (see lesson.md, W3-A).
        $app->bind(CampaignSettings::class, static fn (Application $app): CampaignSettings => CampaignSettings::fromConfig(self::config($app)));

        $app->bind(AudienceRegistry::class, static fn (Application $app): AudienceRegistry => new AudienceRegistry($app->make(CampaignSettings::class)->audiences));

        $app->bind(CampaignStore::class, static function (Application $app): CampaignStore {
            $settings = $app->make(CampaignSettings::class);

            return new CampaignStore(
                $app->make('db'),
                $app->make(ClockInterface::class),
                $app->make(TokenCipher::class),
                $app->make(AudienceRegistry::class),
                $settings->table,
                $settings->connection,
            );
        });

        $app->bind(CampaignJobs::class, static fn (Application $app): CampaignJobs => new CampaignJobs(
            $app->make(Bus::class),
            $app->make(SendJobDispatcher::class),
            $app->make(QueueSettings::class),
        ));

        $app->bind(CampaignCompletion::class, static fn (Application $app): CampaignCompletion => new CampaignCompletion(
            $app->make(CampaignStore::class),
            $app->make(Ledger::class),
            $app->make(Events::class),
        ));

        $app->bind(CampaignPlanner::class, static fn (Application $app): CampaignPlanner => new CampaignPlanner(
            $app->make(CampaignStore::class),
            $app->make(Ledger::class),
            $app->make(CampaignJobs::class),
            $app->make(CampaignCompletion::class),
            $app->make(Events::class),
            $app->make(CampaignSettings::class),
        ));

        $app->bind(CampaignManager::class, static fn (Application $app): CampaignManager => new CampaignManager(
            $app->make(CampaignStore::class),
            $app->make(Ledger::class),
            $app->make(PushDispatcher::class),
            $app->make(CampaignPlanner::class),
            $app->make(CampaignCompletion::class),
            $app->make(CampaignJobs::class),
            $app->make(Events::class),
            $app->make(CampaignSettings::class),
        ));
    }

    public static function boot(Application $app): void
    {
        ModuleMigrations::wire($app, [self::migrationPath()], self::PUBLISH_TAG, self::usable($app));

        if ($app->runningInConsole() && (bool) config('mobile-push.campaigns.enabled', false)) {
            ConsoleCommands::register();
        }

        // Completion reacts to the end of the work (W5-P) instead of waiting for the sweep (still scheduled below).
        if ($app->bound('events')) {
            $complete = static function (string $scope, string $effectId) use ($app): void {
                try {
                    if (! self::usable($app)) {
                        return;
                    }
                    $app->make(CampaignCompletion::class)->checkEffect($scope, $effectId);
                } catch (\Throwable) {
                    // never fails the relay job; the sweep completes the campaign later
                }
            };
            $events = $app->make('events');
            $events->listen(RelayRunFinished::class, static function (RelayRunFinished $e) use ($complete): void {
                foreach ($e->report->effects as $effectId) {
                    $complete($e->scope, $effectId);
                }
            });
            $events->listen(EffectSettled::class, static function (EffectSettled $e) use ($complete): void {
                $complete($e->scope, $e->effectId);
            });
        }

        $schedule = static function (Schedule $schedule) use ($app): void {
            $settings = CampaignSettings::fromConfig(self::config($app));
            if (! $settings->enabled || ! $settings->durable() || ! $settings->scheduleSweeper) {
                return;
            }
            $queue = QueueSettings::fromConfig(self::config($app));
            $schedule->job(new SweepCampaignsJob($queue->timeout), $queue->bulkQueue, $queue->connection)
                ->everyMinute()
                ->name(self::SWEEP_EVENT);
        };
        $app->afterResolving(Schedule::class, $schedule);
        if ($app->resolved(Schedule::class)) {
            $schedule($app->make(Schedule::class));
        }
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/'.self::MIGRATION;
    }

    private static function usable(Application $app): bool
    {
        $settings = CampaignSettings::fromConfig(self::config($app));

        return $settings->enabled && $settings->durable();
    }

    private static function config(Application $app): Repository
    {
        return $app->make('config');
    }
}
