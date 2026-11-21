<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Events;

/** Every slice is written: `planned` unique destinations are in the ledger. Deliveries may still be in progress. */
final readonly class CampaignPlanned
{
    public function __construct(
        public string $scope,
        public string $campaignId,
        public string $name,
        public int $planned,
        public int $slices,
        /** Audience entries that were not a valid destination. */
        public int $invalid,
    ) {}
}
