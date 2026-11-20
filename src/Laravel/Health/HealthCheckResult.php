<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/** One line of a health report. The message always carries the remedy; data is small and bounded. */
final readonly class HealthCheckResult
{
    /** @param  array<string, scalar|null>  $data */
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public array $data = [],
    ) {}

    /** @param  array<string, scalar|null>  $data */
    public static function ok(string $name, string $message, array $data = []): self
    {
        return new self($name, HealthStatus::Ok, $message, $data);
    }

    /** @param  array<string, scalar|null>  $data */
    public static function warning(string $name, string $message, array $data = []): self
    {
        return new self($name, HealthStatus::Warning, $message, $data);
    }

    /** @param  array<string, scalar|null>  $data */
    public static function failed(string $name, string $message, array $data = []): self
    {
        return new self($name, HealthStatus::Failed, $message, $data);
    }

    /** @param  array<string, scalar|null>  $data */
    public static function notVerifiable(string $name, string $message, array $data = []): self
    {
        return new self($name, HealthStatus::NotVerifiable, $message, $data);
    }

    /** @return array{name: string, status: string, message: string, data: array<string, scalar|null>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'status' => $this->status->value, 'message' => $this->message, 'data' => $this->data];
    }
}
