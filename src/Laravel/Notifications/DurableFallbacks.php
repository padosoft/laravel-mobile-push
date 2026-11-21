<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;
use Psr\Log\LoggerInterface;

/**
 * The fallback of a push queued by the notification channel in the DURABLE profile, whose final outcome is only
 * known later (an APNs 410 an hour after queueing, after a deferral).
 *
 * At queue time (PushChannel, QueuesPush / pushQueue()) remember() keeps, for each ledger effect of the push, what the
 * fallback needs: the notifiable and the notification (serialized like a queued Laravel notification, Eloquent
 * models as identifiers through SerializesModels, so they are reloaded fresh), the push effect key and the ids of
 * every effect of the push. It is stored ENCRYPTED with the application key in the fallback cache store
 * (`mobile-push.fallback.cache_store`) for `mobile-push.fallback.idempotency_ttl` seconds, and only when:
 * fallback is enabled, the notification opts in with pushFallback(), the profile is durable, the push has an
 * effect key, and the encrypted record is at most MAX_BYTES.
 *
 * handle() listens to EffectSettled: once EVERY effect of the push has settled, the results of all of them are
 * handed to FallbackEvaluator::evaluate() (same rules as inline: an OutcomeUnknown or an Accepted never falls back;
 * idempotent per push effect key and channel, so a repeated event sends nothing more), and the record is removed.
 *
 * Limits: an effect that settles after the TTL does not fall back; a record bigger than MAX_BYTES is not kept (a
 * warning is logged, nothing else changes); a lost cache loses the pending fallback; a notifiable model deleted in
 * the meantime cannot be reloaded (no fallback). The light profile never falls back for a queued push.
 */
final class DurableFallbacks
{
    public const MAX_BYTES = 65_536;

    private const PREFIX = 'mobile-push:fallback:effect:';

    /**
     * @param  \Closure(): ?DeliveryStore  $deliveries
     * @param  \Closure(): FallbackEvaluator  $evaluator
     */
    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly StringEncrypter $encrypter,
        private readonly \Closure $deliveries,
        private readonly \Closure $evaluator,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  string  $effects  the effect ids returned by PendingSend::queue() (comma-separated when several)
     * @return bool whether the fallback was remembered
     */
    public function remember(string $scope, string $effects, object $notifiable, Notification $notification, string $pushEffectKey): bool
    {
        $ids = array_values(array_filter(explode(',', $effects), static fn (string $id): bool => $id !== ''));
        $settings = FallbackSettings::fromConfig($this->config);
        if ($ids === [] || ! $settings->enabled || $this->config->get('mobile-push.delivery.profile', 'light') !== 'durable') {
            return false;
        }
        if (! method_exists($notification, 'pushFallback') || array_filter((array) $notification->pushFallback($notifiable), static fn (mixed $c): bool => is_string($c) && $c !== '') === []) {
            return false;
        }

        $record = $this->encrypter->encryptString(serialize(new PendingFallback($notifiable, $notification, $pushEffectKey, $scope, $ids)));
        if (strlen($record) > self::MAX_BYTES) {
            $this->logger?->warning('mobile-push: the notification is too large to keep for a durable fallback; no fallback will be evaluated for this push.', [
                'notification' => $notification::class,
                'bytes' => strlen($record),
                'limit' => self::MAX_BYTES,
            ]);

            return false;
        }
        $store = $this->cache->store($settings->cacheStore);
        foreach ($ids as $id) {
            $store->put(self::key($scope, $id), $record, $settings->idempotencyTtl);
        }

        return true;
    }

    /** Listener of Integration\EffectSettled. */
    public function handle(EffectSettled $event): void
    {
        $settings = FallbackSettings::fromConfig($this->config);
        $store = $this->cache->store($settings->cacheStore);
        $record = $store->get(self::key($event->scope, $event->effectId));
        if (! is_string($record)) {
            return;
        }
        $deliveries = ($this->deliveries)();
        if ($deliveries === null) {
            return;
        }

        try {
            $pending = unserialize($this->encrypter->decryptString($record));
        } catch (\Throwable $e) {
            $this->forget($event->scope, [$event->effectId]);
            $this->logger?->warning('mobile-push: a remembered durable fallback could not be restored; it is dropped.', ['exception' => $e::class]);

            return;
        }
        if (! $pending instanceof PendingFallback) {
            $this->forget($event->scope, [$event->effectId]);

            return;
        }

        foreach ($pending->effects as $id) {
            $counts = $deliveries->counts($pending->scope, $id);
            if ($counts->waiting() > 0 || $counts->inFlight() > 0) {
                return; // another effect of the same push is not final yet: its own EffectSettled decides
            }
        }

        $facts = new FallbackFacts;
        foreach ($pending->effects as $id) {
            foreach ($deliveries->results($pending->scope, $id) as $result) {
                $facts->observe($result);
            }
        }
        ($this->evaluator)()->evaluate($pending->notifiable, $pending->notification, $facts, $pending->pushEffectKey);
        $this->forget($pending->scope, $pending->effects);
    }

    /** @param  list<string>  $effects */
    private function forget(string $scope, array $effects): void
    {
        $store = $this->cache->store(FallbackSettings::fromConfig($this->config)->cacheStore);
        foreach ($effects as $id) {
            $store->forget(self::key($scope, $id));
        }
    }

    private static function key(string $scope, string $effectId): string
    {
        return self::PREFIX.hash('sha256', $scope."\0".$effectId);
    }
}
