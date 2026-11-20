<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** What reclaiming expired leases did. */
final readonly class ReclaimReport
{
    public function __construct(
        /** Lease lost before any request left: due again, attempts unchanged. */
        public int $requeued = 0,
        /** Lease lost after the request may have left, retry allowed: due again, budget marked unknown. */
        public int $retriedAfterUnknown = 0,
        /** Lease lost after the request may have left, no retry: ended as OutcomeUnknown. */
        public int $endedUnknown = 0,
    ) {}

    public function total(): int
    {
        return $this->requeued + $this->retriedAfterUnknown + $this->endedUnknown;
    }
}
