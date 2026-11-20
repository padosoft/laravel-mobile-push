<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

/** What can be said about a Google service-account file. The private key is not retained. */
final readonly class ServiceAccountInfo
{
    /**
     * @param  list<string>  $missingFields  required fields that are absent or empty
     * @param  string|null  $keyType  "RSA", "EC", "OTHER", or null when the key is missing or unparseable
     */
    public function __construct(
        public array $missingFields,
        public ?string $type,
        public ?string $clientEmail,
        public ?string $projectId,
        public ?string $privateKeyId,
        public ?string $tokenUri,
        public bool $tokenUriIsOfficial,
        public bool $keyParses,
        public ?string $keyType,
        public ?int $keyBits,
    ) {}

    public function typeIsServiceAccount(): bool
    {
        return $this->type === 'service_account';
    }

    public function keyIsStrongRsa(): bool
    {
        return $this->keyParses && $this->keyType === 'RSA' && ($this->keyBits ?? 0) >= 2048;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'clientEmail' => $this->clientEmail,
            'projectId' => $this->projectId,
            'privateKeyId' => $this->privateKeyId,
            'tokenUri' => $this->tokenUri,
            'private_key' => '[redacted]',
        ];
    }
}
