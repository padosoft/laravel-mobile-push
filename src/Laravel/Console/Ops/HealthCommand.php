<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Laravel\Health\HealthCheckResult;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;

/**
 * Prints the health report for a monitoring system. Liveness and readiness are separate sections; every check says
 * what is wrong and the remedy. It never contacts a provider.
 *
 * Exit code: 0 ok (checks that cannot be verified do not degrade it), 1 warning, 2 failed.
 */
final class HealthCommand extends OpsCommand
{
    protected $signature = 'mobile-push:health
        {--json : Print the report as JSON}';

    protected $description = 'Report liveness and readiness of the package (no request is sent to any provider)';

    public function handle(): int
    {
        $report = $this->laravel->make(HealthInspector::class)->inspect();

        if ($this->wantsJson()) {
            $this->emitJson($report->toArray());

            return $report->exitCode();
        }

        $this->components->info('Mobile push health: '.strtoupper($report->status()->value).' (exit code '.$report->exitCode().')');
        foreach (['Liveness' => [$report->liveness, $report->livenessStatus()], 'Readiness' => [$report->readiness, $report->readinessStatus()]] as $title => [$checks, $status]) {
            $this->newLine();
            $this->line('<options=bold>'.$title.'</> ['.$status->value.']');
            $this->table(['Check', 'Status', 'Message and remedy'], array_map(
                static fn (HealthCheckResult $c): array => [$c->name, $c->status->value, $c->message],
                $checks,
            ));
        }
        $this->newLine();
        $this->line('No request was sent to any provider. A live probe is a separate, explicit operation.');

        return $report->exitCode();
    }
}
