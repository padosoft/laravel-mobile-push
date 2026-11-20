<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;

/**
 * How events expose a destination: its kind, its keyed index (the same value the device registry and the log
 * store), a masked form for humans and the registry reference. Never the raw token.
 */
final readonly class DestinationView
{
    public function __construct(
        public string $kind,
        public string $index,
        public string $masked,
        public ?DestinationRef $ref,
    ) {}

    public static function of(Destination $destination, TokenIndex $index, string $scope): self
    {
        return new self(
            $destination->kind()->value,
            $index->index($destination, $scope),
            TokenMasker::mask($destination->value()),
            $destination->ref(),
        );
    }
}
