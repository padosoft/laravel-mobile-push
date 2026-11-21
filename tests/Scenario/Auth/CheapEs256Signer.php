<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Auth;

use Padosoft\MobilePush\Core\Auth\Signer;

/**
 * Claims ES256 and returns 64 random bytes: for rule tests that build thousands of providers and only
 * count mints. Tests that check the JWT itself use the real OpenSslEs256Signer.
 */
final class CheapEs256Signer implements Signer
{
    public int $signatures = 0;

    public function __construct(private readonly ?string $keyId = 'KEYID12345', private readonly string $algorithm = 'ES256') {}

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function keyId(): ?string
    {
        return $this->keyId;
    }

    public function sign(string $signingInput): string
    {
        $this->signatures++;

        return random_bytes(64);
    }
}
