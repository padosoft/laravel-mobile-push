<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

/** Typed reads of `config()` for the console (config values are mixed). */
final class Cfg
{
    public static function string(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    public static function nullableString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }
}
