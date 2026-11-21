<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Events;

/** Planning has begun: the upper bound of the audience is fixed. Carries ids only, never a destination. */
final readonly class CampaignStarted
{
    public function __construct(
        public string $scope,
        public string $campaignId,
        public string $name,
    ) {}
}
