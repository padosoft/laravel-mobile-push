<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

/** Values of `aps.interruption-level`. */
enum InterruptionLevel: string
{
    case Passive = 'passive';
    case Active = 'active';
    case TimeSensitive = 'time-sensitive';
    case Critical = 'critical';
}
