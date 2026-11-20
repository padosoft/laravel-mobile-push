<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

/**
 * One line of a credential check. `$code` is stable and machine-readable (for alerts and dashboards);
 * `$message` is for humans and always carries the remedy. Neither ever contains key material.
 */
final readonly class Finding
{
    public function __construct(
        public Severity $severity,
        public string $code,
        public string $message,
    ) {}

    public static function ok(string $code, string $message): self
    {
        return new self(Severity::Ok, $code, $message);
    }

    public static function warning(string $code, string $message): self
    {
        return new self(Severity::Warning, $code, $message);
    }

    public static function error(string $code, string $message): self
    {
        return new self(Severity::Error, $code, $message);
    }
}
