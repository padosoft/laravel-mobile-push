<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Module;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptMarkerIssuer;
use Psr\Clock\ClockInterface;

/**
 * Queued sending: the single owner of putting push work on a queue.
 *
 * Binds QueuedSender to ProfiledQueuedSender (light or durable by mobile-push.delivery.profile), the light and
 * durable implementations, the relay, the sweeper and the timeout chain. In the durable profile SweepLedgerJob is
 * scheduled every minute (mobile-push.queue.sweeper.schedule).
 *
 * Settings are read at resolution time (QueueSettings::fromConfig), so nothing here resolves at register().
 */
final class QueueModule implements Module
{
    public const SWEEP_EVENT = 'mobile-push:sweep-ledger';

    public static function register(Application $app): void
    {
        $app->bind(QueueSettings::class, static fn (Application $app): QueueSettings => QueueSettings::fromConfig(self::config($app)));

        $app->bind(SendJobDispatcher::class, static fn (Application $app): SendJobDispatcher => new SendJobDispatcher(
            $app->make(Dispatcher::class),
            $app->make(QueueSettings::class),
            $app->bound('db') ? $app->make('db') : null,
        ));

        $app->bind(LightQueuedSender::class, static fn (Application $app): LightQueuedSender => new LightQueuedSender(
            $app->make(SendJobDispatcher::class),
            $app->make(QueueSettings::class),
            $app->make(ClockInterface::class),
        ));

        $app->bind(DurableQueuedSender::class, static fn (Application $app): DurableQueuedSender => new DurableQueuedSender(
            $app->make(Ledger::class),
            $app->make(SendJobDispatcher::class),
            $app->make(QueueSettings::class),
            self::ledgerConnection($app),
        ));

        $app->bind(QueuedSender::class, static fn (Application $app): ProfiledQueuedSender => new ProfiledQueuedSender($app, $app->make(QueueSettings::class)));

        $app->bind(RelayFollowUps::class, static fn (Application $app): RelayFollowUps => new RelayFollowUps(
            $app->make(CacheFactory::class)->store(self::cacheStore($app)),
            $app->make(SendJobDispatcher::class),
            $app->make(ClockInterface::class),
            $app->make(QueueSettings::class),
        ));

        $app->bind(DeliveryRelay::class, static fn (Application $app): DeliveryRelay => new DeliveryRelay(
            $app->make(Ledger::class),
            $app->make(PushDispatcher::class),
            new RegistryResolver,
            $app->make(RelayFollowUps::class),
            $app->make(ClockInterface::class),
            $app->make(QueueSettings::class),
            class_exists(ReceiptMarkerIssuer::class) && $app->bound(ReceiptMarkerIssuer::class) ? $app->make(ReceiptMarkerIssuer::class) : null,
        ));

        $app->bind(LedgerSweeper::class, static fn (Application $app): LedgerSweeper => new LedgerSweeper(
            $app->make(Ledger::class),
            $app->make(RelayFollowUps::class),
            $app->make(QueueSettings::class),
            $app->make(ClockInterface::class),
        ));
    }

    public static function boot(Application $app): void
    {
        $schedule = static function (Schedule $schedule) use ($app): void {
            $settings = QueueSettings::fromConfig(self::config($app));
            if (! $settings->durable() || ! $settings->scheduleSweeper) {
                return;
            }
            $schedule->job(new SweepLedgerJob($settings->timeout), $settings->queue, $settings->connection)
                ->everyMinute()
                ->name(self::SWEEP_EVENT);
        };

        $app->afterResolving(Schedule::class, $schedule);
        if ($app->resolved(Schedule::class)) {
            $schedule($app->make(Schedule::class));
        }
    }

    /** The timeout chain of the current configuration (for the doctor command). */
    public static function timeouts(Application $app, ?string $queueConnection = null): TimeoutChain
    {
        return QueueTimeouts::fromConfig(self::config($app), $app->make(QueueSettings::class), $queueConnection);
    }

    private static function config(Application $app): Repository
    {
        return $app->make('config');
    }

    private static function ledgerConnection(Application $app): ?string
    {
        $value = self::config($app)->get('mobile-push.ledger.connection');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function cacheStore(Application $app): ?string
    {
        $value = self::config($app)->get('mobile-push.cache.store');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
