<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Laravel\Queue\DeliveryRelay;
use Padosoft\MobilePush\Laravel\Queue\RelayReport;
use Psr\Clock\ClockInterface;

/**
 * Runs the durable relay inline: for cron-driven setups without a queue worker, and for debugging. It does what a
 * RelayDeliveriesJob does, in this process, and prints what happened (counters only).
 */
final class RelayCommand extends OpsCommand
{
    /** Safety valve: a run stops at its chunk deadline and hands over, so this only bounds a runaway loop. */
    private const MAX_RUNS = 1000;

    protected $signature = 'mobile-push:relay
        {--scope= : Scope (tenant) to relay; default is the current scope}
        {--effect= : Relay only the deliveries of this effect id}
        {--once : Do a single relay pass instead of repeating until nothing is due}
        {--json : Print the summary as JSON}';

    protected $description = 'Send the due deliveries of the durable ledger inline (cron-driven relay, debugging)';

    public function handle(): int
    {
        $this->requireDurable('The relay');
        $scope = $this->currentScope();
        $effect = $this->stringOption('effect');

        $ledger = $this->laravel->make(Ledger::class);
        $relay = $this->laravel->make(DeliveryRelay::class);

        // Applied explicitly (the claim does it too) so the summary can say what was recovered.
        $reclaimed = $ledger->reclaimExpiredLeases($scope);
        $expired = $ledger->expireDue($scope);

        $claimed = $handed = $lost = $ignored = $runs = 0;
        /** @var array<string, int> $recorded */
        $recorded = [];
        /** @var array<string, true> $followUps */
        $followUps = [];

        do {
            $report = $relay->run($scope, $effect);
            $runs++;
            $claimed += $report->claimed;
            $handed += $report->handedOver;
            $lost += $report->leasesLost;
            $ignored += $report->ignored;
            foreach ($report->recorded as $status => $count) {
                $recorded[$status] = ($recorded[$status] ?? 0) + $count;
            }
            foreach ($report->followUps as $id) {
                $followUps[$id] = true;
            }
        } while (! $this->option('once') && $this->moreWork($report) && $runs < self::MAX_RUNS);

        $queries = new LedgerQueries($this->laravel);
        $next = $queries->earliestDeferred($scope, $effect);
        ksort($recorded);

        $this->emitSummary('Relay finished', [
            'scope' => $scope,
            'passes' => $runs,
            'claimed' => $claimed,
            'sent' => $handed,
            'recorded' => $recorded,
            'deferred' => [
                'next_attempt_at' => $next?->format(DATE_ATOM),
                'follow_up_relays_scheduled' => count($followUps),
            ],
            'reclaimed_leases' => [
                'requeued_not_started' => $reclaimed->requeued,
                'retried_after_unknown' => $reclaimed->retriedAfterUnknown,
                'ended_unknown_outcome' => $reclaimed->endedUnknown,
            ],
            'expired' => $expired,
            'unknown_outcomes' => ($recorded['outcome_unknown'] ?? 0) + $reclaimed->endedUnknown,
            'leases_lost_before_sending' => $lost,
            'writes_ignored' => $ignored,
            'now' => LedgerTime::date(LedgerTime::ms($this->laravel->make(ClockInterface::class)->now()))->format(DATE_ATOM),
        ]);

        return self::SUCCESS;
    }

    private function moreWork(RelayReport $report): bool
    {
        // A run that stopped at its deadline left work; a run that claimed nothing is the end.
        return $report->deadlineReached && $report->claimed > 0;
    }
}
