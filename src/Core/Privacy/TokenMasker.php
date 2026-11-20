<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

/** Display form of a sensitive value, such as `a1b2c3…9f0e`. Never reveals more than a few characters. */
final readonly class TokenMasker
{
    public static function mask(#[\SensitiveParameter] string $value): string
    {
        $length = strlen($value);

        return match (true) {
            $length < 16 => '[masked]',
            $length < 32 => substr($value, 0, 2).'…'.substr($value, -2),
            default => substr($value, 0, 6).'…'.substr($value, -4),
        };
    }
}
