<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\SettlementProbe;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCompleted;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;

/**
 * Completion detection: planning finished and no delivery of the campaign is waiting or in flight → `completed`,
 * once (compare-and-set on the row version), with CampaignCompleted carrying the final ledger counters.
 *
 * Triggers (W5-P): the end of every relay run that worked on the campaign's effect (Queue\Events\RelayRunFinished)
 * and EffectSettled, so a campaign completes as soon as its last delivery is stored; the planner's last slice and the
 * scheduled sweep stay as safety nets. A check costs an indexed existence probe while deliveries are open
 * (SettlementProbe), and reads the counters once, when the campaign really completes.
 *
 * On completion the campaign's effect is announced with Integration\EffectSettled (when that module is present):
 * a campaign effect grows slice by slice and may look settled between two slices, so it is announced here, once,
 * with the final counters, instead of after every relay run.
 *
 * @internal
 */
final readonly class CampaignCompletion
{
    public function __construct(
        private CampaignStore $store,
        private Ledger $ledger,
        private Dispatcher $events,
    ) {}

    /** Returns the campaign as it is after the check. */
    public function check(Campaign $campaign): Campaign
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($campaign->status !== CampaignStatus::Running || ! $campaign->planningFinished()) {
                return $campaign;
            }
            if ($this->ledger instanceof SettlementProbe && $this->ledger->campaignHasOpenDeliveries($campaign->scope, $campaign->id)) {
                return $campaign;
            }
            $counts = $this->ledger->campaignCounts($campaign->scope, $campaign->id);
            if ($counts->waiting() + $counts->inFlight() > 0) {
                return $campaign;
            }

            $now = $this->store->nowMs();
            if ($this->store->cas($campaign, [
                'status' => CampaignStatus::Completed->value,
                'finished_at' => $now,
                'final_counts' => json_encode($counts->byState, JSON_THROW_ON_ERROR),
            ])) {
                $done = $this->store->get($campaign->scope, $campaign->id);
                $this->events->dispatch(self::event($done, $counts));
                $this->announceEffect($done);

                return $done;
            }
            $campaign = $this->store->latest($campaign->scope, $campaign->id);
        }

        return $campaign;
    }

    /**
     * React to the end of a relay run or an EffectSettled: complete the campaign the effect belongs to, if any.
     * Two primary-key reads (the effect, the campaign) while the campaign is still planning or sending.
     */
    public function checkEffect(string $scope, string $effectId): ?Campaign
    {
        $effect = $this->ledger->effect($scope, $effectId);
        if ($effect === null || $effect->campaignId === null) {
            return null;
        }
        $campaign = $this->store->find($effect->scope, $effect->campaignId);

        return $campaign === null || $campaign->effectId !== $effectId ? null : $this->check($campaign);
    }

    private function announceEffect(Campaign $campaign): void
    {
        if ($campaign->effectId === null || ! class_exists(EffectSettled::class)) {
            return;
        }
        $effect = $this->ledger->effect($campaign->scope, $campaign->effectId);
        if ($effect !== null) {
            $this->events->dispatch(new EffectSettled($campaign->scope, $effect->id, $this->ledger->counts($campaign->scope, $effect->id), $effect->origin));
        }
    }

    private static function event(Campaign $campaign, DeliveryCounts $counts): CampaignCompleted
    {
        return new CampaignCompleted(
            $campaign->scope,
            $campaign->id,
            $campaign->name,
            $campaign->planned,
            $counts->byState,
            $counts->of(DeliveryState::Accepted),
            $counts->notAccepted(),
            $counts->of(DeliveryState::OutcomeUnknown),
            $counts->of(DeliveryState::Skipped) + $counts->of(DeliveryState::Cancelled),
        );
    }
}
