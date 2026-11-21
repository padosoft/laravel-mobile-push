<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCompleted;

/**
 * Completion detection: planning finished and no delivery of the campaign is waiting or in flight → `completed`,
 * once (compare-and-set on the row version), with CampaignCompleted carrying the final ledger counters.
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

                return $done;
            }
            $campaign = $this->store->get($campaign->scope, $campaign->id);
        }

        return $campaign;
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
