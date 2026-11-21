<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Contracts\Config\Repository;

/**
 * mobile-push.fallback.* with explicit defaults.
 *
 *  - enabled              (bool, default true)   master switch; a notification still needs the pushFallback() opt-in
 *  - when_no_destination  (bool, default true)   fall back when the notifiable has no eligible destination at all
 *  - idempotency_ttl      (int seconds, default 86400)  how long "this fallback was sent" is remembered
 *  - cache_store          (?string, default null = default store)  where it is remembered
 */
final readonly class FallbackSettings
{
    public function __construct(
        public bool $enabled = true,
        public bool $whenNoDestination = true,
        public int $idempotencyTtl = 86400,
        public ?string $cacheStore = null,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $store = $config->get('mobile-push.fallback.cache_store');
        $ttl = $config->get('mobile-push.fallback.idempotency_ttl', 86400);

        return new self(
            enabled: filter_var($config->get('mobile-push.fallback.enabled', true), FILTER_VALIDATE_BOOLEAN),
            whenNoDestination: filter_var($config->get('mobile-push.fallback.when_no_destination', true), FILTER_VALIDATE_BOOLEAN),
            idempotencyTtl: is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 86400,
            cacheStore: is_string($store) && $store !== '' ? $store : null,
        );
    }
}
