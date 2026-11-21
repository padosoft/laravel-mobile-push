<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue\Events;

use Padosoft\MobilePush\Laravel\Queue\RelayReport;

/**
 * Durable profile: one relay run (RelayDeliveriesJob) finished and every outcome it produced is stored.
 *
 * Dispatched after EVERY run, scope-wide ones included, with the effects whose deliveries it worked on: the signal
 * for anything that waits for the end of an effect (campaign completion, EffectSettled) without waiting for the
 * scheduled sweep. Identifiers and counters only. A run that died is not reported (the sweeps stay the safety net).
 */
final readonly class RelayRunFinished
{
    public function __construct(
        public string $scope,
        /** The effect the job was for; null for a scope-wide relay (sweeper, coalesced follow-ups). */
        public ?string $effectId,
        public RelayReport $report,
    ) {}
}
