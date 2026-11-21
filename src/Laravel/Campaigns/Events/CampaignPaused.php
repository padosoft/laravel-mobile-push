<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Events;

/** No further slice will be written; deliveries already written proceed. Carries ids only, never a destination. */
final readonly class CampaignPaused
{
    public function __construct(
        public string $scope,
        public string $campaignId,
        public string $name,
    ) {}
}
