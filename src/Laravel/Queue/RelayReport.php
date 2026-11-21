<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** What one relay run did (counters only, no personal data). */
final readonly class RelayReport
{
    /**
     * @param  array<string, int>  $recorded  public status value => deliveries recorded with it
     * @param  list<string>  $followUps  effect ids for which a follow-up relay was dispatched
     */
    public function __construct(
        public int $claimed = 0,
        /** Deliveries handed to the Sender (their attempt was marked as started). */
        public int $handedOver = 0,
        /** Leases lost between the claim and the attempt (another worker, a reclaim or a cancel): not sent. */
        public int $leasesLost = 0,
        public array $recorded = [],
        /** Writes the ledger ignored (stale lease, already final). */
        public int $ignored = 0,
        public array $followUps = [],
        /** True when the run stopped at its chunk deadline with work possibly left. */
        public bool $deadlineReached = false,
        /** @var list<string> the effects whose deliveries this run claimed */
        public array $effects = [],
    ) {}

    public function recordedAs(string $status): int
    {
        return $this->recorded[$status] ?? 0;
    }
}
