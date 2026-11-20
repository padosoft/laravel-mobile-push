<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class FcmFid extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::FcmFid;
    }

    protected static function validate(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{22}$/', $value) === 1 ? null : 'A Firebase installation ID is 22 base64url characters.';
    }
}
