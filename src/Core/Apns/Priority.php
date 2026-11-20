<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Message\MessagePriority;

/** Values of the `apns-priority` header. */
enum Priority: int
{
    /** Send immediately. APNs default when the header is omitted. */
    case High = 10;
    /** Send based on power considerations on the device. */
    case Normal = 5;
    /** Prioritize power considerations over everything else and never wake the device. */
    case Low = 1;

    public static function fromMessagePriority(MessagePriority $priority): self
    {
        return $priority === MessagePriority::High ? self::High : self::Normal;
    }
}
