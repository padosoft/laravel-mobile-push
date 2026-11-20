<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/**
 * Result of HealthInspector: two separate sections.
 *
 *  - liveness: the package can run in this process (extensions, libcurl, config parses);
 *  - readiness: it can do its job now (credentials, cache, ledger, sinks, circuits, execution mode).
 *
 * Neither section ever contains a request to a provider: a live probe is a different, explicit operation.
 *
 * Exit code mapping for `mobile-push:health`: 0 = ok (not_verifiable checks do not degrade), 1 = warning, 2 = failed.
 */
final readonly class HealthReport
{
    public const EXIT_OK = 0;

    public const EXIT_WARNING = 1;

    public const EXIT_FAILED = 2;

    /**
     * @param  list<HealthCheckResult>  $liveness
     * @param  list<HealthCheckResult>  $readiness
     */
    public function __construct(
        public array $liveness,
        public array $readiness,
        public \DateTimeImmutable $generatedAt,
    ) {}

    public function livenessStatus(): HealthStatus
    {
        return self::worst($this->liveness);
    }

    public function readinessStatus(): HealthStatus
    {
        return self::worst($this->readiness);
    }

    public function status(): HealthStatus
    {
        return self::worst([...$this->liveness, ...$this->readiness]);
    }

    public function exitCode(): int
    {
        return $this->status()->exitCode();
    }

    /** @return list<HealthCheckResult> */
    public function checks(): array
    {
        return [...$this->liveness, ...$this->readiness];
    }

    public function check(string $name): ?HealthCheckResult
    {
        foreach ($this->checks() as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     status: string, exit_code: int, generated_at: string, live_probe: string,
     *     liveness: array{status: string, checks: list<array{name: string, status: string, message: string, data: array<string, scalar|null>}>},
     *     readiness: array{status: string, checks: list<array{name: string, status: string, message: string, data: array<string, scalar|null>}>}
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'exit_code' => $this->exitCode(),
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'live_probe' => 'not_run: no request was sent to any provider',
            'liveness' => [
                'status' => $this->livenessStatus()->value,
                'checks' => array_map(static fn (HealthCheckResult $c): array => $c->toArray(), $this->liveness),
            ],
            'readiness' => [
                'status' => $this->readinessStatus()->value,
                'checks' => array_map(static fn (HealthCheckResult $c): array => $c->toArray(), $this->readiness),
            ],
        ];
    }

    /** @param  list<HealthCheckResult>  $checks */
    private static function worst(array $checks): HealthStatus
    {
        $worst = HealthStatus::Ok;
        foreach ($checks as $check) {
            if ($check->status->rank() > $worst->rank()) {
                $worst = $check->status;
            }
        }

        return $worst;
    }
}
