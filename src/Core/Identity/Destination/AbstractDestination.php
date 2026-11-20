<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity\Destination;

use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationRef;

abstract readonly class AbstractDestination implements Destination
{
    final private function __construct(
        #[\SensitiveParameter] private string $value,
        private ?DestinationRef $ref = null,
    ) {}

    /** @throws InvalidDestinationException */
    public static function of(#[\SensitiveParameter] string $value): static
    {
        $value = static::normalize(trim($value));
        $error = static::validate($value);

        if ($error !== null) {
            throw new InvalidDestinationException($error);
        }

        return new static($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function ref(): ?DestinationRef
    {
        return $this->ref;
    }

    public function withRef(DestinationRef $ref): static
    {
        return new static($this->value, $ref);
    }

    public function equals(Destination $other): bool
    {
        return $other->kind() === $this->kind() && hash_equals($this->value, $other->value());
    }

    /** @return array{value: string} never expose the raw value in dumps */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted '.$this->kind()->value.']'];
    }

    protected static function normalize(string $value): string
    {
        return $value;
    }

    /** Return an error message, or null when the value is acceptable. */
    abstract protected static function validate(string $value): ?string;

    /** Lowercase hex of even length, at least 64 chars. Apple documents no fixed token length. */
    protected static function hexError(string $value, string $what): ?string
    {
        return preg_match('/^(?:[0-9a-f]{2}){32,}$/', $value) === 1
            ? null
            : "An {$what} must be a hexadecimal string of at least 64 characters.";
    }
}
