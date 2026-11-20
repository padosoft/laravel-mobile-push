<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

/**
 * Link between a destination and its registry row at the moment the operation was created.
 * A late provider answer for generation N never affects generation N+1, even if the raw token is identical.
 */
final readonly class DestinationRef
{
    public function __construct(
        public string $id,
        public int $generation = 1,
        public ?string $ownerType = null,
        public ?string $ownerId = null,
    ) {}
}
