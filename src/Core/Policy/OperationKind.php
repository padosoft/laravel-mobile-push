<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/** What an operation does to the outside world. */
enum OperationKind: string
{
    case Send = 'send';
    case Validate = 'validate';
    case ChannelManagement = 'channel_management';
    case BroadcastPublish = 'broadcast_publish';
    case TopicSubscription = 'topic_subscription';
    case Probe = 'probe';
}
