<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;

/** Tunables shared by both ledger implementations. */
final readonly class LedgerSettings
{
    public function __construct(
        /** How long a claimed delivery stays reserved for its worker. Must exceed the chunk deadline (plan §6.6). */
        public int $leaseSeconds = 120,
        /** Default deduplication window of an effect key. */
        public int $dedupeSeconds = 86_400,
        /** Rows written / read per statement. */
        public int $chunkSize = 500,
        /** Decides what an expired lease after a started attempt becomes (unknown-outcome policy, attempts, deadline). */
        public RetryPolicy $retry = new RetryPolicy,
    ) {
        if ($leaseSeconds < 1) {
            throw new ConfigurationException('ledger.lease_seconds must be at least 1.');
        }
        if ($dedupeSeconds < 0) {
            throw new ConfigurationException('ledger.dedupe_seconds must not be negative.');
        }
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new ConfigurationException('ledger.chunk_size must be between 1 and 5000.');
        }
    }
}
