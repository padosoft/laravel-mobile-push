<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Auth;

use Padosoft\MobilePush\Core\Auth\Signer;

/** Counts how many signatures were produced: the observable measure of "mints". */
final class CountingSigner implements Signer
{
    public int $signatures = 0;

    public function __construct(private readonly Signer $inner) {}

    public function algorithm(): string
    {
        return $this->inner->algorithm();
    }

    public function keyId(): ?string
    {
        return $this->inner->keyId();
    }

    public function sign(string $signingInput): string
    {
        $this->signatures++;

        return $this->inner->sign($signingInput);
    }
}
