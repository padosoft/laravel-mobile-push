<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\LiveActivity;

/** Values of `aps.event`. */
enum LiveActivityEvent: string
{
    case Start = 'start';
    case Update = 'update';
    case End = 'end';
}
