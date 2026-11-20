<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Auth;

use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/** Parsed Google service-account JSON. The private key never appears in dumps or serialization. */
final readonly class ServiceAccountCredentials
{
    private PrivateKey $key;

    public function __construct(
        public string $clientEmail,
        #[\SensitiveParameter] string $privateKeyPem,
        public ?string $privateKeyId,
        public ?string $projectId,
        public ?string $tokenUri = null,
    ) {
        if ($clientEmail === '' || trim($privateKeyPem) === '') {
            throw new AuthenticationException('A service account needs client_email and private_key.');
        }
        $this->key = PrivateKey::fromPem($privateKeyPem);
    }

    public function privateKey(): PrivateKey
    {
        return $this->key;
    }

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationException('The service-account JSON is not valid JSON.');
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (isset($data['type']) && $data['type'] !== 'service_account') {
            throw new AuthenticationException('The credentials file is not a service-account key.');
        }

        $string = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        $email = $string('client_email');
        $key = $string('private_key');
        if ($email === null || $key === null) {
            throw new AuthenticationException('The service-account JSON lacks client_email or private_key.');
        }

        return new self($email, $key, $string('private_key_id'), $string('project_id'), $string('token_uri'));
    }

    /** Non-secret identity: principal plus key id. */
    public function identity(): string
    {
        return $this->clientEmail.'#'.($this->privateKeyId ?? '');
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return [
            'clientEmail' => $this->clientEmail,
            'privateKey' => '[redacted]',
            'privateKeyId' => $this->privateKeyId,
            'projectId' => $this->projectId,
        ];
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('ServiceAccountCredentials cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('ServiceAccountCredentials cannot be unserialized.');
    }
}
