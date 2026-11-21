<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

/** Display form of a sensitive value, such as `a1b2c3…9f0e`. Never reveals more than a few characters. */
final readonly class TokenMasker
{
    public static function mask(#[\SensitiveParameter] string $value): string
    {
        $length = strlen($value);

        // The kept ends are cut by bytes: with anything but single-byte characters that could split a character and
        // produce a string no JSON encoder accepts. Such a value shows nothing at all.
        if ($length < 16 || preg_match('/[^\x21-\x7E]/', $value) === 1) {
            return '[masked]';
        }

        return $length < 32
            ? substr($value, 0, 2).'…'.substr($value, -2)
            : substr($value, 0, 6).'…'.substr($value, -4);
    }
}
