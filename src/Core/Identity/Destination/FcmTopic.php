<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class FcmTopic extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::FcmTopic;
    }

    protected static function normalize(string $value): string
    {
        return str_starts_with($value, '/topics/') ? substr($value, 8) : $value;
    }

    protected static function validate(string $value): ?string
    {
        return preg_match('/^[a-zA-Z0-9-_.~%]{1,900}$/', $value) === 1 ? null : 'An FCM topic name must match [a-zA-Z0-9-_.~%]{1,900} (without the /topics/ prefix).';
    }
}
