<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

/** ActivityKit's ActivityState as the app reports it. The server cannot observe it on its own. */
enum ClientActivityState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Stale = 'stale';
    case Ended = 'ended';
    case Dismissed = 'dismissed';
}
