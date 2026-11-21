<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Events;

/** Planning continues from the stored cursor. Carries ids only, never a destination. */
final readonly class CampaignResumed
{
    public function __construct(
        public string $scope,
        public string $campaignId,
        public string $name,
    ) {}
}
