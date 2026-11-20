<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;

/**
 * Rebuilds a typed destination from its STORED kind (never from the shape of the value).
 *
 * @internal
 */
final class DestinationFactory
{
    public static function make(DestinationKind $kind, #[\SensitiveParameter] string $value, ?DestinationRef $ref = null): Destination
    {
        $destination = match ($kind) {
            DestinationKind::ApnsDeviceToken => ApnsDeviceToken::of($value),
            DestinationKind::ApnsVoipToken => ApnsVoipToken::of($value),
            DestinationKind::ApnsLiveActivityToken => ApnsLiveActivityToken::of($value),
            DestinationKind::ApnsPushToStartToken => ApnsPushToStartToken::of($value),
            DestinationKind::ApnsBroadcastChannel => ApnsBroadcastChannel::of($value),
            DestinationKind::FcmToken => FcmToken::of($value),
            DestinationKind::FcmFid => FcmFid::of($value),
            DestinationKind::FcmTopic => FcmTopic::of($value),
            DestinationKind::FcmCondition => FcmCondition::of($value),
        };

        return $ref === null ? $destination : $destination->withRef($ref);
    }
}
