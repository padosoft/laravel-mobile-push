<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

/** Why an activity or a channel row was closed (stored in `closed_reason`). */
enum CloseReason: string
{
    case EndAccepted = 'end_accepted';
    case LocalExpiry = 'local_expiry';
    case TokenInvalidated = 'token_invalidated';
    case StartNotAccepted = 'start_not_accepted';
    case Deleted = 'deleted';
    case DeletedRemotely = 'deleted_remotely';
    case NeverCreated = 'never_created';
    case UnknownToApns = 'unknown_to_apns';
}
