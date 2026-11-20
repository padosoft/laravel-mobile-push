<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** Operational metrics of the ledger (a health check reads these; no personal data). */
final readonly class LedgerHealth
{
    public function __construct(
        /** Rows due now (pending/deferred with next_attempt_at <= now). */
        public int $due,
        public int $pending,
        public int $deferred,
        public int $inFlight,
        public int $unknown,
        /** Age of the oldest due row in milliseconds, null when nothing is due: a growing value means the relay is stuck. */
        public ?int $oldestDueAgeMs,
    ) {}
}
