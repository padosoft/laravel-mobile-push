<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/** Default source: knows no connection, so credentials are reported as not verifiable instead of silently ok. */
final class NullConnectionCredentialsSource implements ConnectionCredentialsSource
{
    public function available(): bool
    {
        return false;
    }

    public function connections(): array
    {
        return [];
    }
}
