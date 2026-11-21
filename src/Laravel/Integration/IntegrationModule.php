<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
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
 *  - after every RelayDeliveriesJob of one effect, in the durable profile, checks whether the effect settled and
 *    dispatches EffectSettled once.
 *
 * Config is read when the schedule is built, never at register().
 */
final class IntegrationModule implements Module
{
    public const ALERTS_EVENT = 'mobile-push:alerts';

    public const LIVE_ACTIVITIES_EVENT = 'mobile-push:live-activities-flush';

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

        if ($app->bound('events')) {
            $app->make('events')->listen(JobProcessed::class, static function (JobProcessed $event) use ($app): void {
                self::afterJob($app, $event);
            });
        }
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
    }

    private static function afterJob(Application $app, JobProcessed $event): void
    {
        try {
            if (self::config($app)->get('mobile-push.delivery.profile', 'light') !== 'durable' || $event->job->resolveName() !== RelayDeliveriesJob::class) {
                return;
            }
            $data = $event->job->payload()['data'] ?? null;
            $command = is_array($data) ? ($data['command'] ?? null) : null;
            $job = is_string($command) ? unserialize($command, ['allowed_classes' => [RelayDeliveriesJob::class]]) : null;
            if (! $job instanceof RelayDeliveriesJob || $job->effectId === null) {
                return;
            }
            $app->make(EffectSettlement::class)->check($job->scope, $job->effectId);
        } catch (\Throwable) {
            // a settlement check never fails the job that was just processed
        }
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
