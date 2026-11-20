<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class ApnsBroadcastChannel extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::ApnsBroadcastChannel;
    }

    protected static function validate(string $value): ?string
    {
        return preg_match('#^[A-Za-z0-9+/=_-]{8,256}$#', $value) === 1 ? null : 'A broadcast channel id must be the base64 value returned by APNs.';
    }
}
