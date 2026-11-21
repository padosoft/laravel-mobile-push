<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Laravel\Module;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Registers the "push", "apns" and "fcm" notification channels and the fallback services.
 *
 * Config (all optional, see FallbackSettings): mobile-push.fallback.{enabled, when_no_destination,
 * idempotency_ttl, cache_store}.
 */
final class NotificationsModule implements Module
{
    public static function register(Application $app): void
    {
        $app->singleton(FallbackPolicy::class);
        $app->bind(FallbackEvaluator::class, static fn (Application $app): FallbackEvaluator => new FallbackEvaluator(
            $app->make('config'),
            $app->make(FallbackPolicy::class),
            $app->make('cache'),
            $app->make('events'),
        ));

        $extend = static function (ChannelManager $manager): void {
            $channel = static fn (?Provider $provider): \Closure => static fn (Application $app): PushChannel => new PushChannel(
                $app->make(PushManager::class),
                $app->make(FallbackEvaluator::class),
                $provider,
            );
            $manager->extend('push', $channel(null));
            $manager->extend('apns', $channel(Provider::Apns));
            $manager->extend('fcm', $channel(Provider::Fcm));
        };
        $app->afterResolving(ChannelManager::class, $extend);
        if ($app->resolved(ChannelManager::class)) {
            $extend($app->make(ChannelManager::class));
        }
    }

    public static function boot(Application $app): void {}
}
