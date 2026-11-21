<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

/**
 * Renders docs/configuration.md from ConfigSchema. Console-independent: `ConfigReference::write()` regenerates the
 * file (the docs task and the test call it; a test fails when the committed file differs from toMarkdown()).
 */
final class ConfigReference
{
    public static function path(): string
    {
        return dirname(__DIR__, 3).'/docs/configuration.md';
    }

    /** Regenerate the document; returns the path written. */
    public static function write(?string $path = null): string
    {
        $path ??= self::path();
        if (file_put_contents($path, self::toMarkdown()) === false) {
            throw new \RuntimeException('Cannot write '.$path.'.');
        }

        return $path;
    }

    public static function isCurrent(?string $path = null): bool
    {
        $current = @file_get_contents($path ?? self::path());

        return $current === self::toMarkdown();
    }

    public static function toMarkdown(): string
    {
        $lines = [
            '# Configuration reference',
            '',
            '<!-- GENERATED from Padosoft\MobilePush\Laravel\Integration\ConfigSchema by ConfigReference::write(). Do not edit by hand. -->',
            '',
            'Every key of `config/mobile-push.php`, its environment variable, default and allowed values. Keys are relative to `mobile-push` (read them with `config(\'mobile-push.<key>\')`). Every optional module is off by default. Publish the file with `php artisan vendor:publish --tag=mobile-push-config`.',
            '',
            '## Sections',
            '',
        ];
        foreach (ConfigSchema::sections() as $section) {
            $lines[] = sprintf('- [%s](#%s) (%d keys)', $section['title'], self::anchor($section['title']), count($section['keys']));
        }
        $lines[] = '';

        foreach (ConfigSchema::sections() as $section) {
            $lines[] = '## '.$section['title'];
            $lines[] = '';
            $lines[] = $section['intro'];
            $lines[] = '';
            if ($section['id'] === 'connections') {
                $lines = [...$lines, ...self::connectionTable($section['keys']), ...self::connectionExamples()];

                continue;
            }
            $lines[] = '| Key | Env | Default | Type | Allowed | Description | Module |';
            $lines[] = '|---|---|---|---|---|---|---|';
            foreach ($section['keys'] as $key) {
                $lines[] = sprintf('| `%s` | %s | %s | %s | %s | %s | %s |',
                    $key->key,
                    $key->env === null ? '–' : '`'.$key->env.'`',
                    self::cell(self::defaultOf($key)),
                    self::cell('`'.$key->type.'`'),
                    self::cell(self::allowedOf($key)),
                    self::cell($key->description),
                    $key->module,
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<ConfigKey>  $keys
     * @return list<string>
     */
    private static function connectionTable(array $keys): array
    {
        $lines = [
            'Keys of one connection (`connections.<name>.<key>`). Transport keys left out fall back to the `transport` block.',
            '',
            '| Key | Default | Type | Allowed | Description |',
            '|---|---|---|---|---|',
        ];
        foreach ($keys as $key) {
            if (! $key->perConnection()) {
                continue;
            }
            $lines[] = sprintf('| `%s` | %s | %s | %s | %s |',
                substr($key->key, strlen('connections.*.')),
                self::cell($key->default === null ? '–' : self::defaultOf($key)),
                self::cell('`'.$key->type.'`'),
                self::cell(self::allowedOf($key)),
                self::cell($key->description),
            );
        }
        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private static function connectionExamples(): array
    {
        return [
            'APNs with a `.p8` key, driven by `.env`:',
            '',
            '```php',
            "'connections' => [",
            "    'ios' => [",
            "        'driver' => 'apns',",
            "        'auth' => 'token',",
            "        'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),",
            "        'key_id' => env('MOBILE_PUSH_APNS_KEY_ID'),",
            "        'key' => ['path' => env('MOBILE_PUSH_APNS_KEY_PATH')],          // or 'base64' => env('MOBILE_PUSH_APNS_KEY_BASE64')",
            "        'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),",
            "        'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),",
            "        'live' => ['allow' => false, 'only_to' => []],",
            '    ],',
            '],',
            '```',
            '',
            'FCM HTTP v1 with a service account:',
            '',
            '```php',
            "'connections' => [",
            "    'android' => [",
            "        'driver' => 'fcm',",
            "        'auth' => 'service_account',",
            "        'credentials' => ['path' => env('MOBILE_PUSH_FCM_CREDENTIALS_PATH')],  // or 'base64' => env('MOBILE_PUSH_FCM_CREDENTIALS_BASE64')",
            "        'project_id' => env('MOBILE_PUSH_FCM_PROJECT_ID'),                   // null = from the JSON",
            "        'timeout_ms' => 10000,                                               // a per-connection transport override",
            '    ],',
            '],',
            '```',
            '',
        ];
    }

    private static function defaultOf(ConfigKey $key): string
    {
        $value = '`'.self::export($key->default).'`';

        return $key->defaultNote === null ? $value : $value.' ('.$key->defaultNote.')';
    }

    private static function allowedOf(ConfigKey $key): string
    {
        if ($key->allowed !== null) {
            return implode(', ', array_map(static fn (string $v): string => '`'.$v.'`', $key->allowed));
        }

        return $key->constraint ?? '';
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => str_contains((string) $value, '.') ? (string) $value : $value.'.0',
            is_string($value) && class_exists($value) => '\\'.$value.'::class',
            is_string($value) => "'".$value."'",
            is_array($value) => self::exportArray($value),
            default => get_debug_type($value),
        };
    }

    /** @param  array<mixed>  $value */
    private static function exportArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = array_is_list($value) ? self::export($v) : self::export($k).' => '.self::export($v);
        }

        return '['.implode(', ', $parts).']';
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }

    private static function anchor(string $title): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9 -]/', '', $title)));

        return (string) preg_replace('/ /', '-', $slug);
    }
}
