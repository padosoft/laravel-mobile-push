<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

/**
 * A typed destination. The type is always explicit: the package never infers provider, token type,
 * bundle or environment from the shape of a string.
 */
interface Destination
{
    public function kind(): DestinationKind;

    /** The raw token / FID / topic / condition / channel id. Sensitive: never log it, use Redactor. */
    public function value(): string;

    /** Registry reference, when the destination comes from the device registry. */
    public function ref(): ?DestinationRef;

    public function withRef(DestinationRef $ref): static;

    public function equals(Destination $other): bool;
}
