<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

final readonly class DoctorCheck
{
    public function __construct(
        public string $section,
        public DoctorStatus $status,
        public string $label,
        public ?string $remedy = null,
    ) {}

    public static function ok(string $section, string $label): self
    {
        return new self($section, DoctorStatus::Ok, $label);
    }

    public static function info(string $section, string $label): self
    {
        return new self($section, DoctorStatus::Info, $label);
    }

    public static function warning(string $section, string $label, ?string $remedy = null): self
    {
        return new self($section, DoctorStatus::Warning, $label, $remedy);
    }

    public static function notVerifiable(string $section, string $label, ?string $remedy = null): self
    {
        return new self($section, DoctorStatus::NotVerifiable, $label, $remedy);
    }

    public static function failed(string $section, string $label, ?string $remedy = null): self
    {
        return new self($section, DoctorStatus::Failed, $label, $remedy);
    }

    /** @return array{section: string, status: string, label: string, remedy: string|null} */
    public function toArray(): array
    {
        return ['section' => $this->section, 'status' => $this->status->value, 'label' => $this->label, 'remedy' => $this->remedy];
    }
}
