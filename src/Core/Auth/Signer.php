<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/**
 * Signs a JWS signing input. Implement it to keep the private key inside a KMS/HSM: the package
 * never needs the key material itself, only signatures.
 */
interface Signer
{
    /** JWS algorithm: "ES256" (APNs) or "RS256" (Google service account). */
    public function algorithm(): string;

    /** Value for the JWS "kid" header, when the provider requires one. */
    public function keyId(): ?string;

    /**
     * Raw JWS signature bytes for `$signingInput` (base64url(header).base64url(claims)).
     * ES256 MUST return the 64-byte R||S concatenation, not OpenSSL's DER encoding.
     *
     * @throws AuthenticationException
     */
    public function sign(string $signingInput): string;
}
