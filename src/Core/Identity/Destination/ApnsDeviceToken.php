<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class ApnsDeviceToken extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::ApnsDeviceToken;
    }

    protected static function normalize(string $value): string
    {
        return strtolower($value);
    }

    protected static function validate(string $value): ?string
    {
        return self::hexError($value, 'APNs device token');
    }
}
