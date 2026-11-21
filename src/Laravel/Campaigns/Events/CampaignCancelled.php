<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Events;

/**
 * Cancelled: nothing further is planned and the deliveries not yet in flight were cancelled in the ledger. What was
 * already accepted (or is in flight now) cannot be retracted.
 */
final readonly class CampaignCancelled
{
    /** @param  array<string, int>  $counts  ledger counters by delivery state right after the cancel */
    public function __construct(
        public string $scope,
        public string $campaignId,
        public string $name,
        /** Deliveries the cancel changed (cancelled before any attempt, or flagged while in flight). */
        public int $cancelledDeliveries,
        public array $counts,
    ) {}
}
