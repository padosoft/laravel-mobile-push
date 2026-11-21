<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

/**
 * The person a cap or a badge is about. A registry destination with an owner counts as that owner (type + id), so
 * three phones of one user are one subject; a destination without an owner is its own subject, identified by its
 * keyed TokenIndex value, never by the raw token. The strings are only ever hashed into store keys.
 */
final class Subject
{
    public static function owner(string $ownerType, string $ownerId): string
    {
        return 'o:'.strlen($ownerType).':'.$ownerType.':'.$ownerId;
    }

    public static function forDestination(Destination $destination, TokenIndex $index, string $scope): string
    {
        $ref = $destination->ref();
        if ($ref !== null && $ref->ownerType !== null && $ref->ownerId !== null) {
            return self::owner($ref->ownerType, $ref->ownerId);
        }

        return 'd:'.$index->index($destination, $scope);
    }
}
