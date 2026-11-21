<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\SettlementProbe;

/**
 * Detects that an effect has settled after a relay run and dispatches EffectSettled once.
 *
 * Cost: one indexed existence probe per relay run of a single effect while work is open; the effect read and the
 * counts query only when it looks settled. A scope-wide relay (no effect id) is not inspected here. Campaign effects
 * are announced by the campaign module when the campaign completes. "Once" is kept
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
        // W5-P: an indexed existence probe first (no row of a final state is read while work is open), the full
        // counters only once the effect looks settled.
        if ($this->ledger instanceof SettlementProbe && $this->ledger->hasOpenDeliveries($scope, $effectId)) {
            return null;
        }
        $effect = $this->ledger->effect($scope, $effectId);
        if ($effect === null || $effect->campaignId !== null) {
            // A campaign effect grows slice by slice and can look settled between two slices: the campaign module
            // announces it once, when the campaign completes (CampaignCompletion).
            return null;
        }
        $counts = $this->ledger->counts($scope, $effectId);
        if ($counts->total() === 0 || $counts->waiting() > 0 || $counts->inFlight() > 0) {
            return null;
        }
        if ($this->cache !== null) {
            try {
                // Once per set of deliveries: an effect that received more destinations later can settle again.
                if (! $this->cache->add($this->prefix.':settled:'.hash('sha256', $scope.'|'.$effectId).':'.$counts->total(), 1, self::TTL_SECONDS)) {
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
