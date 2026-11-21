<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * Optional set-based variants of the worker writes of DeliveryStore, for a relay that handles a window of
 * deliveries at a time. Same rules, same guards (lease owner + version, cancel flag) and the same outcomes as the
 * one-row methods; only the number of statements differs (a few per window instead of several per delivery).
 *
 * A store that does not implement it is driven one row at a time. Implemented by both ledgers of the package.
 */
interface DeliveryBatches
{
    /**
     * markAttemptStarted() for every lease. Returns the delivery ids whose attempt was marked started: only those
     * may be sent. The others lost their lease (expired, cancelled, taken over).
     *
     * @param  list<Lease>  $leases
     * @return list<string>
     */
    public function markAttemptsStarted(array $leases): array;

    /**
     * record() for every entry, keyed by delivery id. An entry that throws is not partially written: the caller
     * keeps it and may retry it with record().
     *
     * @param  list<array{0: Lease, 1: SendResult, 2: ?RetryBudget}>  $records
     * @return array<string, Recording>
     */
    public function recordMany(array $records): array;
}
