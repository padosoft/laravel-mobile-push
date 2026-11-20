<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

use Padosoft\MobilePush\Core\Identity\Environment;

/** What can be said about an APNs client certificate. Holds no private material. */
final readonly class CertificateInfo
{
    /**
     * @param  string  $fingerprintSha256  lowercase hex SHA-256 of the DER certificate (mTLS pool identity)
     * @param  list<string>  $topics  bundle ids found in the Apple extensions; empty when not decodable
     * @param  bool|null  $keyMatches  null when no private key was supplied
     */
    public function __construct(
        public ?string $commonName,
        public ?string $teamId,
        public \DateTimeImmutable $notBefore,
        public \DateTimeImmutable $notAfter,
        public string $fingerprintSha256,
        public array $topics,
        public ApnsCertificateKind $kind,
        public ?bool $keyMatches,
    ) {}

    /** Whole days left (rounded down); negative once expired. */
    public function daysRemaining(\DateTimeImmutable $now): int
    {
        return (int) floor(($this->notAfter->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->notAfter->getTimestamp() <= $now->getTimestamp();
    }

    public function isNotYetValid(\DateTimeImmutable $now): bool
    {
        return $this->notBefore->getTimestamp() > $now->getTimestamp();
    }

    /** The one environment this certificate is bound to, or null when universal or unknown. */
    public function environment(): ?Environment
    {
        return match ($this->kind) {
            ApnsCertificateKind::Sandbox => Environment::Sandbox,
            ApnsCertificateKind::Production => Environment::Production,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'commonName' => $this->commonName,
            'teamId' => $this->teamId,
            'notAfter' => $this->notAfter->format(DATE_ATOM),
            'fingerprintSha256' => $this->fingerprintSha256,
            'kind' => $this->kind->value,
            'keyMatches' => $this->keyMatches,
        ];
    }
}
