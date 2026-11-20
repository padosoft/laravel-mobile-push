<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

/** AndroidConfig.priority (Discovery enum NORMAL, HIGH). Not available on AndroidConfigV2. */
enum AndroidPriority: string
{
    case Normal = 'NORMAL';
    case High = 'HIGH';
}
