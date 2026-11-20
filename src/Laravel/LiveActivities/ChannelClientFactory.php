<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Apns\Channels\ChannelClient;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/** Builds the APNs channel-management client of one binding (management and publishing are separate profiles). */
interface ChannelClientFactory
{
    public function for(BindingRef $binding): ChannelClient;
}
