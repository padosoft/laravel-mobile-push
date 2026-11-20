<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Channels;

/**
 * The channel's message storage policy, fixed at creation.
 *
 * Apple (sending-channel-management-requests-to-apns, read 2026-11-20): "Message storage policy configured as part
 * of the request can't be modified later." and "Specify 0 for No Message Stored. Specify 1 for Most Recent Message
 * Stored." Most Recent stores a message for at most 8 hours.
 */
enum StoragePolicy: int
{
    case NoStorage = 0;
    case MostRecent = 1;
}
