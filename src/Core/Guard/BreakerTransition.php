<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/** State change returned to the caller so the framework layer can dispatch events. Nothing is emitted here. */
enum BreakerTransition: string
{
    case None = 'none';
    case Opened = 'opened';
    case HalfOpened = 'half_opened';
    case Reopened = 'reopened';
    case Closed = 'closed';
    /** The authentication breaker switched to the secondary credential (decided once). */
    case FailedOver = 'failed_over';
}
