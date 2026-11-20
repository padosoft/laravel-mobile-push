<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class FcmToken extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::FcmToken;
    }

    protected static function validate(string $value): ?string
    {
        return $value !== '' && strlen($value) <= 4096 && preg_match('/\\s/', $value) !== 1 ? null : 'An FCM registration token must be a non-empty string without whitespace.';
    }
}
