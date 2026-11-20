<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

/** The findings of a credential check. */
final readonly class CheckResult
{
    /** @param  list<Finding>  $findings */
    public function __construct(public array $findings = []) {}

    public function merge(self $other): self
    {
        return new self([...$this->findings, ...$other->findings]);
    }

    public function hasErrors(): bool
    {
        return $this->count(Severity::Error) > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->count(Severity::Warning) > 0;
    }

    /** True when there is no error (warnings are allowed). */
    public function passes(): bool
    {
        return ! $this->hasErrors();
    }

    public function worst(): Severity
    {
        return $this->hasErrors() ? Severity::Error : ($this->hasWarnings() ? Severity::Warning : Severity::Ok);
    }

    /** @return list<string> */
    public function codes(?Severity $severity = null): array
    {
        $codes = [];
        foreach ($this->findings as $finding) {
            if ($severity === null || $finding->severity === $severity) {
                $codes[] = $finding->code;
            }
        }

        return $codes;
    }

    public function find(string $code): ?Finding
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        return null;
    }

    private function count(Severity $severity): int
    {
        return count(array_filter($this->findings, static fn (Finding $f): bool => $f->severity === $severity));
    }
}
