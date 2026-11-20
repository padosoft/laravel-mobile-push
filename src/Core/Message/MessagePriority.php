<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message;

enum MessagePriority: string
{
    /** Deliver immediately (APNs 10, FCM HIGH). */
    case High = 'high';
    /** Power-aware delivery (APNs 5, FCM NORMAL). */
    case Normal = 'normal';
}
