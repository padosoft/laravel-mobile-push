<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class ApnsLiveActivityToken extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::ApnsLiveActivityToken;
    }

    protected static function normalize(string $value): string
    {
        return strtolower($value);
    }

    protected static function validate(string $value): ?string
    {
        return self::hexError($value, 'Live Activity push token');
    }
}
