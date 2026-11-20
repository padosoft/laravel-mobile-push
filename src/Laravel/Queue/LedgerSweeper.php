<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Ledger\Ledger;
use Psr\Clock\ClockInterface;

/**
 * The recovery half of the outbox. For each scope with work: apply the lease-expiry rules, end the overdue
 * deliveries as Expired, and dispatch a scope-wide relay when deliveries are due.
 *
 * This is what picks up an intent whose relay job never reached the queue (a crash between the commit and the
 * dispatch, which after-commit cannot close), a relay job lost by the queue, or a follow-up that was never
 * scheduled. Run it every minute (QueueModule schedules SweepLedgerJob; `sweep()` can be called by a command).
 */
final readonly class LedgerSweeper
{
    public function __construct(
        private Ledger $ledger,
        private RelayFollowUps $followUps,
        private QueueSettings $settings,
        private ClockInterface $clock,
    ) {}

    public function sweep(): SweepReport
    {
        $scopes = $this->ledger->scopesWithWork($this->settings->sweepScopes);
        $requeued = $retried = $endedUnknown = $expired = 0;
        $dispatched = [];

        foreach ($scopes as $scope) {
            $reclaim = $this->ledger->reclaimExpiredLeases($scope, $this->settings->chunk * 4);
            $requeued += $reclaim->requeued;
            $retried += $reclaim->retriedAfterUnknown;
            $endedUnknown += $reclaim->endedUnknown;
            $expired += $this->ledger->expireDue($scope, $this->settings->chunk * 4);

            if ($this->ledger->health($scope)->due > 0 && $this->followUps->schedule($scope, null, $this->clock->now())) {
                $dispatched[] = $scope;
            }
        }

        return new SweepReport($scopes, $requeued, $retried, $endedUnknown, $expired, $dispatched);
    }
}
