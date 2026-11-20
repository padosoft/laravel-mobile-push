<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Check for spatie/laravel-health, a suggested (dev) dependency. This file is only ever autoloaded by code that has
 * already seen `Spatie\Health\Checks\Check`: HealthModule registers it behind a class_exists() guard, so a project
 * without the package never loads it.
 *
 *     Health::checks([MobilePushHealthCheck::new()]);
 *
 * Mapping: ok (also not_verifiable) -> ok, warning -> warning, failed -> failed. Runs the readiness AND liveness
 * checks; makes no request to a provider.
 */
final class MobilePushHealthCheck extends Check
{
    public function __construct(private readonly HealthInspector $inspector)
    {
        parent::__construct();
    }

    public function run(): Result
    {
        $report = $this->inspector->inspect();

        $problems = [];
        foreach ($report->checks() as $check) {
            if ($check->status->rank() > 0) {
                $problems[] = $check->name.': '.$check->message;
            }
        }
        $result = Result::make()->meta([
            'liveness' => $report->livenessStatus()->value,
            'readiness' => $report->readinessStatus()->value,
        ]);

        return match ($report->status()) {
            HealthStatus::Failed => $result->failed(implode(' | ', $problems))->shortSummary('failed'),
            HealthStatus::Warning => $result->warning(implode(' | ', $problems))->shortSummary('warning'),
            default => $result->ok()->shortSummary('ok'),
        };
    }
}
