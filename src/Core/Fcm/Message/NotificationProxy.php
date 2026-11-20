<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

/** AndroidNotification.proxy (Discovery enum). Only on AndroidConfig, not on AndroidConfigV2. */
enum NotificationProxy: string
{
    case Unspecified = 'PROXY_UNSPECIFIED';
    case Allow = 'ALLOW';
    case Deny = 'DENY';
    case IfPriorityLowered = 'IF_PRIORITY_LOWERED';
}
