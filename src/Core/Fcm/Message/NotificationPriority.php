<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

/** AndroidNotification.notification_priority (Discovery enum). Different from AndroidPriority (delivery priority). */
enum NotificationPriority: string
{
    case Unspecified = 'PRIORITY_UNSPECIFIED';
    case Min = 'PRIORITY_MIN';
    case Low = 'PRIORITY_LOW';
    case Default = 'PRIORITY_DEFAULT';
    case High = 'PRIORITY_HIGH';
    case Max = 'PRIORITY_MAX';
}
