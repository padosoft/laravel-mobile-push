<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

/** Builds a TokenStore key from NON-secret identifiers. It is a name, not cryptographic material. */
final class TokenCacheKey
{
    public static function make(string ...$parts): string
    {
        $material = '';
        foreach ($parts as $part) {
            $material .= strlen($part).':'.$part.'|';
        }

        return 'mobile-push:token:'.hash('sha256', $material);
    }
}
