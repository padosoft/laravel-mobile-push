<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

enum TokenState: string
{
    case Active = 'active';
    /** Routing suspicion (wrong environment or topic): kept, never sent to until the app reports it again. */
    case Quarantined = 'quarantined';
    /** APNs stated the token is gone. */
    case Invalidated = 'invalidated';
}
