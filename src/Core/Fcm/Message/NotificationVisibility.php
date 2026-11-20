<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

/** AndroidNotification.visibility (Discovery enum). */
enum NotificationVisibility: string
{
    case Unspecified = 'VISIBILITY_UNSPECIFIED';
    case Private = 'PRIVATE';
    case Public = 'PUBLIC';
    case Secret = 'SECRET';
}
