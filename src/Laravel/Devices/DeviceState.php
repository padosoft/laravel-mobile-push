<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

enum DeviceState: string
{
    case Active = 'active';
    /** Routing suspicion: suspended, kept, never sent to until the app registers it again. */
    case Quarantined = 'quarantined';
    /** The provider stated the registration is gone. */
    case Invalidated = 'invalidated';
}
