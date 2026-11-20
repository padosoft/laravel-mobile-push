<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/** ES256 (ECDSA P-256 + SHA-256) signer backed by ext-openssl; returns the 64-byte JWS R||S form. */
final class OpenSslEs256Signer implements Signer
{
    public function __construct(
        private readonly PrivateKey $key,
        private readonly ?string $keyId = null,
    ) {
        if (! $key->isEc() || $key->curve() !== 'prime256v1') {
            throw new AuthenticationException(sprintf(
                'ES256 requires an EC P-256 (prime256v1) private key; got %s%s.',
                $key->typeName(),
                $key->curve() !== null ? ' on curve '.$key->curve() : '',
            ));
        }
    }

    public function algorithm(): string
    {
        return 'ES256';
    }

    public function keyId(): ?string
    {
        return $this->keyId;
    }

    public function sign(string $signingInput): string
    {
        $der = '';
        $ok = openssl_sign($signingInput, $der, $this->key->handle(), OPENSSL_ALGO_SHA256);
        while (is_string(openssl_error_string())) {
            // drain
        }
        if (! $ok || ! is_string($der)) {
            throw new AuthenticationException('ES256 signing failed.');
        }

        return DerSignature::toJose($der, 32);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['algorithm' => 'ES256', 'keyId' => $this->keyId, 'key' => '[redacted]'];
    }
}
