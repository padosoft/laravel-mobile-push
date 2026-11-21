<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Padosoft\MobilePush\Core\Identity\BindingRef;

/** What an audience may know when it is read: the campaign's tenant and app binding. */
final readonly class AudienceContext
{
    public function __construct(
        public string $scope,
        public BindingRef $binding,
        public string $campaignId,
    ) {}
}
