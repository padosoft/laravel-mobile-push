<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Ledger\Ledger;

/**
 * Detects that an effect has settled after a relay run and dispatches EffectSettled once.
 *
 * Cost: one counts query per relay run of a single effect (plus one effect read when it settled). A scope-wide
 * relay (no effect id) is not inspected: the follow-ups and the sweeper relay effects one by one. "Once" is kept
 * with an `add` on the package cache (a lost cache can at worst dispatch the event again; listeners must be
 * idempotent, as FallbackEvaluator is).
 */
final readonly class EffectSettlement
{
    public const TTL_SECONDS = 86_400;

    public function __construct(
        private Ledger $ledger,
        private Dispatcher $events,
        private ?Repository $cache = null,
        private string $prefix = 'mobile-push',
    ) {}

    /** @return EffectSettled|null the event dispatched now, or null (not settled, unknown, or already announced) */
    public function check(string $scope, string $effectId): ?EffectSettled
    {
        $counts = $this->ledger->counts($scope, $effectId);
        if ($counts->total() === 0 || $counts->waiting() > 0 || $counts->inFlight() > 0) {
            return null;
        }
        $effect = $this->ledger->effect($scope, $effectId);
        if ($effect === null) {
            return null;
        }
        if ($this->cache !== null) {
            try {
                if (! $this->cache->add($this->prefix.':settled:'.hash('sha256', $scope.'|'.$effectId), 1, self::TTL_SECONDS)) {
                    return null;
                }
            } catch (\Throwable) {
                // without the cache the event may repeat; listeners are idempotent
            }
        }

        $event = new EffectSettled($scope, $effectId, $counts, $effect->origin);
        $this->events->dispatch($event);

        return $event;
    }
}
