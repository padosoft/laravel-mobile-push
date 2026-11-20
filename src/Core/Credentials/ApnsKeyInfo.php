<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

/** What can be said about a `.p8` APNs signing key. Holds only public data. */
final readonly class ApnsKeyInfo
{
    /**
     * @param  string  $publicFingerprintSha256  lowercase hex SHA-256 of the DER SubjectPublicKeyInfo
     */
    public function __construct(
        public string $keyType,
        public ?string $curve,
        public string $publicFingerprintSha256,
        public bool $isEcP256,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['keyType' => $this->keyType, 'curve' => $this->curve, 'publicFingerprintSha256' => $this->publicFingerprintSha256, 'key' => '[redacted]'];
    }
}
