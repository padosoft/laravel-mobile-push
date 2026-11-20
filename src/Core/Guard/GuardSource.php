<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/** Which breaker produced a state change reported by Guards::before(). */
enum GuardSource: string
{
    case Auth = 'auth';
    case DestinationQuality = 'destination_quality';
}
