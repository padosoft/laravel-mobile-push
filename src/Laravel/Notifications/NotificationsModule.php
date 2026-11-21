<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;
use Padosoft\MobilePush\Laravel\Module;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Psr\Log\LoggerInterface;

/**
 * Registers the "push", "apns" and "fcm" notification channels and the fallback services; in boot(), the
 * EffectSettled listener of DurableFallbacks (the fallback of a push queued in the durable profile).
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

        $app->singleton(DurableFallbacks::class, static fn (Application $app): DurableFallbacks => new DurableFallbacks(
            $app->make('config'),
            $app->make('cache'),
            $app->make(StringEncrypter::class),
            static fn (): ?DeliveryStore => $app->bound(DeliveryStore::class) ? $app->make(DeliveryStore::class) : null,
            static fn (): FallbackEvaluator => $app->make(FallbackEvaluator::class),
            $app->bound(LoggerInterface::class) || $app->bound('log') ? $app->make(LoggerInterface::class) : null,
        ));

        $extend = static function (ChannelManager $manager): void {
            $channel = static fn (?Provider $provider): \Closure => static fn (Application $app): PushChannel => new PushChannel(
                $app->make(PushManager::class),
                $app->make(FallbackEvaluator::class),
                $provider,
                $app->make(DurableFallbacks::class),
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

    public static function boot(Application $app): void
    {
        // Durable profile: a queued push falls back once all its effects settled (see DurableFallbacks).
        if (class_exists(EffectSettled::class) && $app->bound('events')) {
            $app->make('events')->listen(EffectSettled::class, static function (EffectSettled $event) use ($app): void {
                $app->make(DurableFallbacks::class)->handle($event);
            });
        }
    }
}
