<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/** RS256 (RSASSA-PKCS1-v1_5 + SHA-256) signer backed by ext-openssl. */
final class OpenSslRs256Signer implements Signer
{
    public function __construct(
        private readonly PrivateKey $key,
        private readonly ?string $keyId = null,
    ) {
        if (! $key->isRsa() || $key->bits() < 2048) {
            throw new AuthenticationException(sprintf(
                'RS256 requires an RSA private key of at least 2048 bits; got %s%s.',
                $key->typeName(),
                $key->isRsa() ? ' of '.$key->bits().' bits' : '',
            ));
        }
    }

    public function algorithm(): string
    {
        return 'RS256';
    }

    public function keyId(): ?string
    {
        return $this->keyId;
    }

    public function sign(string $signingInput): string
    {
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $this->key->handle(), OPENSSL_ALGO_SHA256);
        while (is_string(openssl_error_string())) {
            // drain
        }
        if (! $ok || ! is_string($signature)) {
            throw new AuthenticationException('RS256 signing failed.');
        }

        return $signature;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['algorithm' => 'RS256', 'keyId' => $this->keyId, 'key' => '[redacted]'];
    }
}
