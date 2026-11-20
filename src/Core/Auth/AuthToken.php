<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

/**
 * A bearer credential for a provider (APNs provider JWT or Google OAuth access token).
 */
final readonly class AuthToken
{
    public function __construct(
        #[\SensitiveParameter] public string $value,
        public \DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}

    /** Identifies this exact token for compare-and-swap refreshes without exposing its value. */
    public function generation(): string
    {
        return substr(hash('sha256', $this->value), 0, 16);
    }

    public function ageSeconds(\DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->issuedAt->getTimestamp());
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
