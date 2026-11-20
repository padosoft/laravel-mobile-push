<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

/** Payload size limits in bytes, measured on the encoded JSON body. */
final class ApnsPayloadLimits
{
    public const STANDARD = 4096;

    public const VOIP = 5120;

    public const BROADCAST = 5120;

    public static function for(PushType $type, DestinationKind $kind): int
    {
        if ($kind === DestinationKind::ApnsBroadcastChannel) {
            return self::BROADCAST;
        }

        return $type === PushType::Voip ? self::VOIP : self::STANDARD;
    }
}
