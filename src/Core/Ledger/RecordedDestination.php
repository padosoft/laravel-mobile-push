<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;

/**
 * A destination as the ledger remembers it when it does not hold the raw value: a registry device (the registry is
 * the source of the token, resolve it through ref()) or a row whose personal data was erased by forget.
 *
 * It can be read in a report but never sent: value() throws, so it cannot be turned silently into an empty token.
 */
final readonly class RecordedDestination implements Destination
{
    public function __construct(
        private DestinationKind $kind,
        /** The ledger's logical destination key (registry device id + generation, or the HMAC index). */
        public string $logicalKey,
        private ?DestinationRef $ref = null,
    ) {}

    public function kind(): DestinationKind
    {
        return $this->kind;
    }

    public function value(): string
    {
        throw new \LogicException('The ledger does not keep the raw value of this destination: resolve it through ref() (device registry).');
    }

    public function ref(): ?DestinationRef
    {
        return $this->ref;
    }

    public function withRef(DestinationRef $ref): static
    {
        return new self($this->kind, $this->logicalKey, $ref);
    }

    public function equals(Destination $other): bool
    {
        return $other instanceof self && $other->kind === $this->kind && hash_equals($this->logicalKey, $other->logicalKey);
    }
}
