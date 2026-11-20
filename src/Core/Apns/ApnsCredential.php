<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Environment;

/**
 * One APNs credential: a provider token (.p8 key → ES256 JWT, via an AccessTokenProvider) or an mTLS client
 * certificate (PEM blobs, never file paths). The team id is always declared: it keys the provider account.
 *
 * The PEM blobs are kept inside a closure, so they never appear in var_export()/print_r() of this object.
 */
final readonly class ApnsCredential
{
    /** @var \Closure(): array{0: string|null, 1: string|null, 2: string|null} certificate, key, passphrase */
    private \Closure $pems;

    private function __construct(
        public string $teamId,
        public ?AccessTokenProvider $tokenProvider,
        public ?string $keyId,
        /** SHA-256 fingerprint (hex) of the client certificate, null for token auth. */
        public ?string $certificateFingerprint,
        /** Environment the credential is restricted to (certificates are per environment), null = any. */
        public ?Environment $environment,
        ?string $certificatePem = null,
        ?string $privateKeyPem = null,
        ?string $passphrase = null,
    ) {
        if (preg_match('/^[A-Za-z0-9]{1,32}$/', $teamId) !== 1) {
            throw new ConfigurationException('The APNs team id must be alphanumeric (as shown in the Apple developer account).');
        }
        $this->pems = static fn (): array => [$certificatePem, $privateKeyPem, $passphrase];
    }

    /** Token authentication. `$keyId` is the .p8 key id; with an ApnsTokenProvider both ids are checked against it. */
    public static function token(AccessTokenProvider $provider, string $teamId, string $keyId, ?Environment $environment = null): self
    {
        if (preg_match('/^[A-Za-z0-9]{1,32}$/', $keyId) !== 1) {
            throw new ConfigurationException('The APNs key id must be alphanumeric.');
        }
        if ($provider instanceof ApnsTokenProvider && $provider->identity() !== $teamId.':'.$keyId) {
            throw new ConfigurationException('The APNs token provider was built for another team id or key id than the one declared.');
        }

        return new self($teamId, $provider, $keyId, null, $environment);
    }

    /**
     * mTLS authentication with a client certificate. `$privateKeyPem` may be null when the certificate PEM also
     * holds the key. Certificates are bound to one environment: declare it so the config can check it.
     */
    public static function certificate(
        string $teamId,
        #[\SensitiveParameter] string $certificatePem,
        #[\SensitiveParameter] ?string $privateKeyPem = null,
        #[\SensitiveParameter] ?string $passphrase = null,
        ?Environment $environment = null,
    ): self {
        // OpenSSL reports parse errors as PHP warnings: keep them (and their text) out of the error handler.
        set_error_handler(static fn (): bool => true);
        try {
            $fingerprint = openssl_x509_fingerprint($certificatePem, 'sha256');
            $key = openssl_pkey_get_private($privateKeyPem ?? $certificatePem, $passphrase ?? '');
            $matches = $fingerprint !== false && $key !== false && openssl_x509_check_private_key($certificatePem, $key);
        } finally {
            restore_error_handler();
            while (is_string(openssl_error_string())) {
                // drain: never expose OpenSSL's text
            }
        }
        if ($fingerprint === false) {
            throw new ConfigurationException('The APNs client certificate is not a valid PEM certificate.');
        }
        if ($key === false) {
            throw new ConfigurationException('The private key of the APNs client certificate cannot be read (missing, malformed or wrong passphrase).');
        }
        if (! $matches) {
            throw new ConfigurationException('The private key does not belong to the APNs client certificate.');
        }

        return new self($teamId, null, null, strtolower($fingerprint), $environment, $certificatePem, $privateKeyPem, $passphrase);
    }

    public function isCertificate(): bool
    {
        return $this->tokenProvider === null;
    }

    /** Non-secret identity: `token:<team>:<key>` or `cert:<sha-256 fingerprint>`. */
    public function identity(): string
    {
        return $this->isCertificate()
            ? 'cert:'.$this->certificateFingerprint
            : 'token:'.$this->teamId.':'.$this->keyId;
    }

    public function certificatePem(): ?string
    {
        return ($this->pems)()[0];
    }

    public function privateKeyPem(): ?string
    {
        return ($this->pems)()[1];
    }

    public function passphrase(): ?string
    {
        return ($this->pems)()[2];
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return [
            'teamId' => $this->teamId,
            'identity' => $this->identity(),
            'environment' => $this->environment?->value,
            'secrets' => '[redacted]',
        ];
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('An APNs credential cannot be serialized.');
    }
}
