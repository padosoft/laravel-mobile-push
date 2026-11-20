<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/**
 * A parsed asymmetric private key (PKCS#8 `.p8`, PKCS#1 or SEC1 PEM). Never prints, serializes or
 * dumps its material.
 */
final class PrivateKey
{
    private function __construct(
        private readonly \OpenSSLAsymmetricKey $key,
        private readonly int $type,
        private readonly int $bits,
        private readonly ?string $curve,
    ) {}

    public static function fromPem(#[\SensitiveParameter] string $pem, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        $normalized = self::normalizePem($pem);

        $key = $normalized === '' ? false : openssl_pkey_get_private($normalized, $passphrase ?? '');
        while (is_string(openssl_error_string())) {
            // never expose OpenSSL's error text: it may echo parser state
        }

        if ($key === false) {
            throw new AuthenticationException('The private key could not be parsed (wrong format or passphrase).');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new AuthenticationException('The private key could not be inspected.');
        }

        $curve = null;
        if ($details['type'] === OPENSSL_KEYTYPE_EC) {
            $ec = $details['ec'] ?? null;
            $curve = is_array($ec) && is_string($ec['curve_name'] ?? null) ? $ec['curve_name'] : null;
        }

        $type = $details['type'];
        $bits = $details['bits'];
        if (! is_int($type) || ! is_int($bits)) {
            throw new AuthenticationException('The private key could not be inspected.');
        }

        return new self($key, $type, $bits, $curve);
    }

    /** Turn literal "\n" sequences, CRLF, single-line PEM and a missing trailing newline into canonical PEM. */
    public static function normalizePem(#[\SensitiveParameter] string $pem): string
    {
        $pem = trim($pem);
        if ($pem === '') {
            return '';
        }

        $pem = str_replace(['\\r\\n', '\\n'], "\n", $pem);
        $pem = str_replace(["\r\n", "\r"], "\n", $pem);

        if (! str_contains($pem, "\n")
            && preg_match('/^(-----BEGIN [A-Z0-9 ]+-----)\s*(.+?)\s*(-----END [A-Z0-9 ]+-----)$/s', $pem, $m) === 1
            && ! str_contains($m[2], ':')) {
            $pem = $m[1]."\n".chunk_split((string) preg_replace('/\s+/', '', $m[2]), 64, "\n").$m[3];
        }

        return rtrim($pem, "\n")."\n";
    }

    public function handle(): \OpenSSLAsymmetricKey
    {
        return $this->key;
    }

    public function isEc(): bool
    {
        return $this->type === OPENSSL_KEYTYPE_EC;
    }

    public function isRsa(): bool
    {
        return $this->type === OPENSSL_KEYTYPE_RSA;
    }

    /** "EC", "RSA" or "OTHER". */
    public function typeName(): string
    {
        return match ($this->type) {
            OPENSSL_KEYTYPE_EC => 'EC',
            OPENSSL_KEYTYPE_RSA => 'RSA',
            default => 'OTHER',
        };
    }

    /** Curve name for EC keys (e.g. "prime256v1"), null otherwise. */
    public function curve(): ?string
    {
        return $this->curve;
    }

    public function bits(): int
    {
        return $this->bits;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['key' => '[redacted]', 'type' => $this->typeName()];
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A PrivateKey cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A PrivateKey cannot be unserialized.');
    }
}
