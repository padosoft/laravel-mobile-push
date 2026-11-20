<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

final readonly class FcmCondition extends AbstractDestination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::FcmCondition;
    }

    protected static function validate(string $value): ?string
    {
        return $value !== '' && strlen($value) <= 2048 && str_contains($value, 'in topics') ? null : "An FCM condition looks like \"'a' in topics && 'b' in topics\".";
    }
}
