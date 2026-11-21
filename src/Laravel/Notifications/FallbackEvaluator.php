<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Ramsey\Uuid\Uuid;

/**
 * Applies the fallback rules to the results of ONE push effect for ONE notifiable and, when they say so, sends the
 * notification through the fallback channels the notification opted in to.
 *
 * Called by PushChannel right after an inline send. With the durable profile (or any deferred outcome) call it
 * later with the final results, for example from a ledger-completion hook:
 *
 *     app(FallbackEvaluator::class)->evaluate($user, $notification, $finalResults, $pushEffectKey);
 *
 * Deferred results mean "not decided": evaluate() does nothing for them. With the light profile nothing calls
 * evaluate() again, so a failure that happens after the Deferred outcome never falls back; with the durable
 * profile the host can call it once the effect is final. The evaluator is idempotent per (push effect key,
 * channel): the second call for the same notification and channel sends nothing.
 *
 * Per channel the order is: opt-in (HasPushFallback) -> FallbackRules -> FallbackPolicy -> idempotency claim in
 * the cache -> Notification::sendNow() for that channel alone, as a clone with its own deterministic id.
 */
final class FallbackEvaluator
{
    public function __construct(
        private readonly Config $config,
        private readonly FallbackPolicy $policy,
        private readonly CacheFactory $cache,
        private readonly Events $events,
    ) {}

    /**
     * @param  iterable<SendResult>|FallbackFacts  $results
     * @param  string|null  $pushEffectKey  the key the push was sent with; null = derive it (EffectKeys::forPush())
     */
    public function evaluate(object $notifiable, Notification $notification, iterable|FallbackFacts $results, ?string $pushEffectKey = null): FallbackOutcome
    {
        $facts = $results instanceof FallbackFacts ? $results : FallbackFacts::fromResults($results);
        $settings = FallbackSettings::fromConfig($this->config);

        $requested = $settings->enabled ? self::requestedChannels($notifiable, $notification) : [];
        $decision = FallbackRules::decide($facts, $settings->whenNoDestination);
        if ($requested === [] || ! $decision->fallback) {
            return new FallbackOutcome($decision);
        }

        $key = $pushEffectKey ?? EffectKeys::forPush($notifiable, $notification);
        $cache = $this->cache->store($settings->cacheStore);
        $dispatched = [];
        $withheld = [];

        foreach ($requested as $channel) {
            if (! $this->policy->allows($notifiable, $notification, $channel)) {
                $withheld[$channel] = 'policy_denied';

                continue;
            }

            $claim = null;
            if ($key !== null) {
                $claim = 'mobile-push:fallback:'.EffectKeys::forFallback($key, $channel);
                if (! $cache->add($claim, 1, $settings->idempotencyTtl)) {
                    $withheld[$channel] = 'already_sent';

                    continue;
                }
            }

            $copy = clone $notification;
            if ($key !== null) {
                $copy->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'mobile-push:'.EffectKeys::forFallback($key, $channel))->toString();
            }
            try {
                NotificationFacade::sendNow($notifiable, $copy, [$channel]);
            } catch (\Throwable $e) {
                if ($claim !== null) {
                    $cache->forget($claim); // the fallback did not happen: a retry may try again
                }

                throw $e;
            }
            $dispatched[] = $channel;
        }

        if ($dispatched !== []) {
            $this->events->dispatch(new PushFellBack(
                $notifiable::class,
                ($id = EffectKeys::keyOf($notifiable)) === '' ? null : $id,
                $notification::class,
                is_string($notification->id ?? null) ? $notification->id : null,
                $dispatched,
                $decision->reason,
                $facts->counts(),
            ));
        }

        return new FallbackOutcome($decision, $dispatched, $withheld);
    }

    /** @return list<string> */
    private static function requestedChannels(object $notifiable, Notification $notification): array
    {
        if (! method_exists($notification, 'pushFallback')) {
            return [];
        }
        $channels = [];
        foreach ((array) $notification->pushFallback($notifiable) as $channel) {
            if (is_string($channel) && $channel !== '' && ! in_array($channel, ['push', 'apns', 'fcm'], true) && ! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
