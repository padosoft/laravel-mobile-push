<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\Logging\LoggingModule;
use Padosoft\MobilePush\Laravel\Module;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Glue between modules that do not know each other.
 *
 * register():
 *  - ConnectionCredentialsSource = ManagerCredentialsSource (the push manager's connections, inspected), replacing the
 *    health module's "not verifiable" default;
 *  - EffectSettlement (durable profile).
 *
 * boot():
 *  - schedules OpsAlerter::evaluate() every minute when `mobile-push.alerts.enabled` (error rate, ledger backlog,
 *    failing sinks);
 *  - schedules ActivityManager::flushDue() every minute when `mobile-push.live_activities.enabled` (same-second
 *    coalesced updates and planned retries of Live Activities);
 *  - with `mobile-push.maintenance.schedule` (default true) schedules once a day at `maintenance.daily_at` the prune
 *    commands of the ENABLED modules only (ledger deliveries on the durable profile, devices, database log sink, Live
 *    Activities, campaigns) and `mobile-push:credentials:check`; each withoutOverlapping(), and onOneServer() only when
 *    the default cache store is shared between servers (redis, memcached, database, dynamodb);
 *  - after every RelayDeliveriesJob of one effect, in the durable profile, checks whether the effect settled and
 *    dispatches EffectSettled once.
 *
 * Config is read when the schedule is built, never at register().
 */
final class IntegrationModule implements Module
{
    public const ALERTS_EVENT = 'mobile-push:alerts';

    public const LIVE_ACTIVITIES_EVENT = 'mobile-push:live-activities-flush';

    /** Prefix of the name of every maintenance event: `mobile-push:maintenance:<command>`. */
    public const MAINTENANCE_PREFIX = 'mobile-push:maintenance:';

    /** Cache drivers whose locks every server shares: only with one of them is onOneServer() safe. */
    private const SHARED_LOCK_DRIVERS = ['redis', 'memcached', 'database', 'dynamodb'];

    public static function register(Application $app): void
    {
        // singleton() replaces the health module's bindIf() default whichever module registers first.
        $app->singleton(ConnectionCredentialsSource::class, static fn (Application $app): ConnectionCredentialsSource => new ManagerCredentialsSource(
            static fn (): PushManager => $app->make(PushManager::class),
            static fn (): CredentialCheck => $app->make(CredentialCheck::class),
        ));

        $app->bind(EffectSettlement::class, static function (Application $app): EffectSettlement {
            $prefix = self::config($app)->get('mobile-push.cache.prefix', 'mobile-push');
            $store = self::config($app)->get('mobile-push.cache.store');

            return new EffectSettlement(
                $app->make(Ledger::class),
                $app->make(Dispatcher::class),
                $app->make('cache')->store(is_string($store) && $store !== '' ? $store : null),
                is_string($prefix) && $prefix !== '' ? $prefix : 'mobile-push',
            );
        });
    }

    public static function boot(Application $app): void
    {
        $schedule = static function (Schedule $schedule) use ($app): void {
            self::schedule($app, $schedule);
        };
        $app->afterResolving(Schedule::class, $schedule);
        if ($app->resolved(Schedule::class)) {
            $schedule($app->make(Schedule::class));
        }

        // EffectSettled is announced from the relay itself (QueueModule listens to RelayRunFinished and asks
        // EffectSettlement): nothing here needs to look at the jobs the application processes.
    }

    /** The scheduled events of this module for the current config (idempotent: an event is added once). */
    public static function schedule(Application $app, Schedule $schedule): void
    {
        $existing = [];
        foreach ($schedule->events() as $event) {
            $existing[(string) $event->description] = true;
        }
        $config = self::config($app);

        if (self::flag($config->get('mobile-push.alerts.enabled', false)) && ! isset($existing[self::ALERTS_EVENT])) {
            $schedule->call(static function () use ($app): void {
                $app->make(OpsAlerter::class)->evaluate();
            })->everyMinute()->name(self::ALERTS_EVENT)->withoutOverlapping();
        }

        if (self::flag($config->get('mobile-push.live_activities.enabled', false)) && ! isset($existing[self::LIVE_ACTIVITIES_EVENT])) {
            $schedule->call(static function () use ($app): void {
                $app->make(ActivityManager::class)->flushDue();
            })->everyMinute()->name(self::LIVE_ACTIVITIES_EVENT)->withoutOverlapping();
        }

        self::scheduleMaintenance($app, $schedule, $existing);
    }

    /**
     * The artisan commands the daily maintenance runs for the current config, in schedule order. Prune commands only
     * for the modules that are enabled; `mobile-push:credentials:check` always (it inspects credentials and raises the
     * expiry alerts when `alerts.enabled`; with alerts off it only computes the findings, so the outcome is visible
     * through its exit code (a failed scheduled task on warnings/errors) and `mobile-push:health`, nothing is sent).
     *
     * @return list<string>
     */
    public static function maintenanceCommands(Application $app): array
    {
        $config = self::config($app);
        $durable = $config->get('mobile-push.delivery.profile', 'light') === 'durable';
        $commands = [];
        if ($durable) {
            $commands[] = 'mobile-push:deliveries:prune';
        }
        if (self::flag($config->get('mobile-push.devices.enabled', false))) {
            $commands[] = 'mobile-push:devices:prune';
        }
        if (in_array('database', LoggingModule::sinkNames($app), true)) {
            $commands[] = 'mobile-push:logs:prune';
        }
        if (self::flag($config->get('mobile-push.live_activities.enabled', false))) {
            $commands[] = 'mobile-push:live-activity:prune';
        }
        if ($durable && self::flag($config->get('mobile-push.campaigns.enabled', false))) {
            $commands[] = 'mobile-push:campaign:prune';
        }
        $commands[] = 'mobile-push:credentials:check';

        return $commands;
    }

    /** @param  array<string, true>  $existing  descriptions of the events already on the schedule */
    private static function scheduleMaintenance(Application $app, Schedule $schedule, array $existing): void
    {
        $config = self::config($app);
        if (! self::flag($config->get('mobile-push.maintenance.schedule', true))) {
            return;
        }

        $at = $config->get('mobile-push.maintenance.daily_at', '03:17');
        if (! is_string($at) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($at)) !== 1) {
            throw new ConfigurationException('mobile-push.maintenance.daily_at must be a time of day as HH:MM (24 hours), for example "03:17".');
        }
        $at = trim($at);
        $oneServer = self::cacheIsShared($config);

        foreach (self::maintenanceCommands($app) as $command) {
            $name = self::MAINTENANCE_PREFIX.$command;
            if (isset($existing[$name])) {
                continue;
            }
            // One hour of lock: a crashed run must not block the next day's run for the 24 hours of the default.
            $event = $schedule->command($command)->dailyAt($at)->name($name)->withoutOverlapping(60);
            if ($oneServer) {
                $event->onOneServer();
            }
        }
    }

    /** Whether the default cache store is one every server sees (so a schedule lock on it really is global). */
    private static function cacheIsShared(Repository $config): bool
    {
        $store = $config->get('cache.default');
        $driver = is_string($store) ? $config->get('cache.stores.'.$store.'.driver') : null;

        return is_string($driver) && in_array($driver, self::SHARED_LOCK_DRIVERS, true);
    }

    private static function flag(mixed $value): bool
    {
        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
    }

    private static function config(Application $app): Repository
    {
        return $app->make('config');
    }
}
