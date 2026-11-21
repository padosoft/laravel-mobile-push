<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\ReceiptFunnel;

/**
 * Where a campaign is, read from the ledger (never from counters kept elsewhere).
 *
 * Reading rules:
 *  - `accepted` means the provider accepted the request, not that a phone showed it.
 *  - `unknown` is reported apart: the response was lost (or the worker died after sending); it may have been
 *    accepted. It is never counted as failed.
 *  - For topics, conditions and broadcast channels one delivery is one publication: `recipientsKnown` is false and
 *    no number here is a number of recipients.
 *  - `estimate` is an estimate (see ThroughputEstimate).
 */
final readonly class CampaignProgress
{
    public int $waiting;

    public int $inFlight;

    public int $accepted;

    public int $rejected;

    public int $failed;

    public int $expired;

    public int $unknown;

    public int $skipped;

    public int $cancelled;

    public int $simulated;

    public int $validated;

    public int $terminal;

    /**
     * @param  list<string>  $notes  plain statements a report should show next to the numbers
     */
    public function __construct(
        public Campaign $campaign,
        /** Deliveries in the ledger for this campaign (every state). */
        public DeliveryCounts $counts,
        public bool $recipientsKnown,
        public ?ReceiptFunnel $funnel,
        public ?ThroughputEstimate $estimate,
        public array $notes = [],
    ) {
        $this->waiting = $counts->waiting();
        $this->inFlight = $counts->inFlight();
        $this->accepted = $counts->of(DeliveryState::Accepted);
        $this->rejected = $counts->of(DeliveryState::Rejected);
        $this->failed = $counts->of(DeliveryState::Failed);
        $this->expired = $counts->of(DeliveryState::Expired);
        $this->unknown = $counts->of(DeliveryState::OutcomeUnknown);
        $this->skipped = $counts->of(DeliveryState::Skipped);
        $this->cancelled = $counts->of(DeliveryState::Cancelled);
        $this->simulated = $counts->of(DeliveryState::Simulated);
        $this->validated = $counts->of(DeliveryState::Validated);
        $this->terminal = $counts->terminal();
    }

    /** Deliveries in the ledger (= unique destinations planned so far). */
    public function total(): int
    {
        return $this->counts->total();
    }

    public function planningFinished(): bool
    {
        return $this->campaign->planningFinished();
    }

    /**
     * Terminal share of what is planned so far, 0-100. While planning, this is NOT the share of the audience.
     * Null when nothing is planned yet.
     */
    public function percent(): ?float
    {
        $total = $this->total();

        return $total === 0 ? ($this->planningFinished() ? 100.0 : null) : round($this->terminal * 100 / $total, 2);
    }

    /** Known not accepted (rejected + failed + expired); unknown outcomes are not included. */
    public function notAccepted(): int
    {
        return $this->counts->notAccepted();
    }

    /** @return array<string, int> */
    public function byState(): array
    {
        return $this->counts->byState;
    }
}
