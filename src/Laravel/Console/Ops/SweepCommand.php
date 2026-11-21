<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Laravel\Queue\LedgerSweeper;

/**
 * Runs the ledger sweeper inline: lost leases are applied, overdue deliveries expired, and a scope-wide relay is
 * handed to the queue for every scope that has due work.
 */
final class SweepCommand extends OpsCommand
{
    protected $signature = 'mobile-push:sweep
        {--json : Print the summary as JSON}';

    protected $description = 'Run the durable ledger sweeper inline (reclaim lost leases, expire overdue deliveries, hand due work to the relay)';

    public function handle(): int
    {
        $this->requireDurable('The sweeper');

        $report = $this->laravel->make(LedgerSweeper::class)->sweep();

        $this->emitSummary('Sweep finished', [
            'scopes_visited' => $report->scopes,
            'leases_requeued_not_started' => $report->requeued,
            'leases_retried_after_unknown' => $report->retriedAfterUnknown,
            'ended_unknown_outcome' => $report->endedUnknown,
            'expired' => $report->expired,
            'relay_jobs_dispatched_for_scopes' => $report->relaysDispatched,
        ]);
        if (! $this->wantsJson() && $report->relaysDispatched !== []) {
            $this->components->info('Relay jobs went to the queue. To process them in this process run: php artisan mobile-push:relay --scope=<scope>');
        }

        return self::SUCCESS;
    }
}
