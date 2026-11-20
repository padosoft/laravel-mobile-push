<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

/**
 * Resolves an exportable secret (PEM key, service-account JSON) from a vault. Referenced from config by
 * class-string so config:cache keeps working. For non-exportable keys implement Signer instead.
 */
interface SecretResolver
{
    public function resolve(string $reference): string;
}
