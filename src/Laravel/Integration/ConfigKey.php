<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

/**
 * One configuration key of `config/mobile-push.php`, machine-readable (ConfigSchema).
 *
 * `$key` is dotted and relative to `mobile-push`; a `*` segment stands for any name (the connection name of
 * `connections.*.driver`). A key of type map or list owns everything below it (`log.levels.accepted`).
 */
final readonly class ConfigKey
{
    /**
     * @param  list<string>|null  $allowed  the allowed values when the key is an enumeration
     */
    public function __construct(
        public string $key,
        public string $type,
        public mixed $default,
        public ?string $env,
        public string $description,
        public string $module,
        public ?array $allowed = null,
        /** A range or rule for the value, e.g. ">= 1", "1..10000". */
        public ?string $constraint = null,
        /** Human text when the default is computed rather than literal (e.g. "derived from APP_KEY"). */
        public ?string $defaultNote = null,
    ) {}

    public function perConnection(): bool
    {
        return str_starts_with($this->key, 'connections.*.');
    }

    /** Whether the key owns the keys below it (maps and lists are documented as a whole). */
    public function isContainer(): bool
    {
        return str_starts_with($this->type, 'map') || str_starts_with($this->type, 'list');
    }

    /** @return list<string> */
    public function segments(): array
    {
        return explode('.', $this->key);
    }
}
