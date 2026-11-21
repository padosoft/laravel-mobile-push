<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Driver\TransmitObserver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Ledger\DeliveryBatches;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Ledger\Lease;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * Per-call observer of the relay: records each result with the Sender's final budget into the ledger as soon as it
 * is produced, before the relay job is acknowledged.
 *
 * Observers are isolated by the Sender (an exception here is swallowed), so a failed write is kept and retried by
 * flush(); the relay stops handing new items to the Sender after the first failed write, so a ledger outage costs
 * at most the item in flight, not the rest of the chunk.
 *
 * Batching (W5-P): with a ledger that implements DeliveryBatches, the results of one Sender window are kept until
 * the relay is about to hand the next window over (or the report ends) and written with recordMany(): a couple of
 * statements per window instead of two per delivery. Nothing is on the network while they are written, and a
 * result is never held past the start of the next request: a worker that dies in between loses at most the
 * results of the window it had just finished, which the lease-expiry rule then ends as unknown outcomes, exactly
 * as for a worker that died one statement earlier.
 */
final class RelayRecorder implements SendObserver, TransmitObserver
{
    /** @var array<string, Lease> delivery id => lease, for the deliveries handed to the Sender */
    private array $leases = [];

    /** @var array<string, string> delivery id => effect id */
    private array $effects = [];

    /** @var array<string, true> */
    private array $done = [];

    /** @var array<string, array{0: Lease, 1: SendResult, 2: ?RetryBudget}> */
    private array $unsaved = [];

    /** @var array<string, array{0: Lease, 1: SendResult, 2: ?RetryBudget}> results of the current window, not written yet */
    private array $buffered = [];

    /** @var array<string, \DateTimeImmutable> effect id => earliest next attempt of a deferred delivery */
    public array $deferredUntil = [];

    /** @var array<string, int> */
    public array $byStatus = [];

    public int $ignored = 0;

    /** Deliveries whose attempt was marked started (handed to the transport). */
    public int $started = 0;

    /** Deliveries withheld right before transmission because their lease was lost. */
    public int $lost = 0;

    /** @var array<string, true> delivery ids marked started in this chunk */
    private array $startedIds = [];

    public function __construct(private readonly DeliveryStore $ledger) {}

    public function expect(string $deliveryId, string $effectId, Lease $lease): void
    {
        $this->leases[$deliveryId] = $lease;
        $this->effects[$deliveryId] = $effectId;
    }

    public function handled(string $deliveryId): bool
    {
        return isset($this->done[$deliveryId]);
    }

    public function hasFailures(): bool
    {
        return $this->unsaved !== [];
    }

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        if ($item->deliveryId !== null) {
            $this->save($item->deliveryId, $result, $budget);
        }
    }

    /** Record a result the observer did not see (a dispatcher that answered without running the pipeline). */
    public function save(string $deliveryId, SendResult $result, ?RetryBudget $budget = null): void
    {
        $lease = $this->leases[$deliveryId] ?? null;
        if ($lease === null || isset($this->done[$deliveryId])) {
            return;
        }
        $this->done[$deliveryId] = true;

        if ($this->ledger instanceof DeliveryBatches) {
            $this->buffered[$deliveryId] = [$lease, $result, $budget];

            return;
        }

        try {
            $this->noted($deliveryId, $result, $this->ledger->record($lease, $result, $budget));
        } catch (\Throwable) {
            $this->unsaved[$deliveryId] = [$lease, $result, $budget];
        }
    }

    /**
     * Write the results kept for the current window (one recordMany()). Called by the relay before it hands the
     * next window to the Sender and once the report is consumed. A failure keeps them for flush().
     */
    public function flushWindow(): void
    {
        if ($this->buffered === [] || ! $this->ledger instanceof DeliveryBatches) {
            return;
        }
        $batch = $this->buffered;
        $this->buffered = [];

        try {
            $recorded = $this->ledger->recordMany(array_values($batch));
        } catch (\Throwable) {
            $this->unsaved += $batch;

            return;
        }
        foreach ($batch as $deliveryId => [, $result]) {
            $this->noted($deliveryId, $result, $recorded[$deliveryId] ?? Recording::NotFound);
        }
    }

    /**
     * The Sender is about to hand these requests to the transport: write the outcomes of the previous window, then
     * mark exactly these attempts started (one statement with a DeliveryBatches ledger). A request whose lease was
     * lost is withheld (not sent) and nothing is written for it. When the ledger is not taking writes every request
     * is withheld: an attempt whose start cannot be recorded must not leave.
     */
    public function beforeTransmit(array $items): array
    {
        $this->flushWindow();

        $toStart = $withheld = [];
        foreach ($items as $requestId => $item) {
            $id = $item->deliveryId;
            $lease = $id === null ? null : ($this->leases[$id] ?? null);
            if ($id === null || $lease === null || isset($this->startedIds[$id])) {
                continue;   // not ours, or already started (an in-process retry of the same attempt plan)
            }
            if ($this->hasFailures()) {
                $this->done[$id] = true;
                $withheld[] = (string) $requestId;

                continue;
            }
            $toStart[(string) $requestId] = $lease;
        }
        if ($toStart === []) {
            return $withheld;
        }

        $started = [];
        if ($this->ledger instanceof DeliveryBatches) {
            $started = array_fill_keys($this->ledger->markAttemptsStarted(array_values($toStart)), true);
        } else {
            foreach ($toStart as $lease) {
                if ($this->ledger->markAttemptStarted($lease)) {
                    $started[$lease->deliveryId] = true;
                }
            }
        }
        foreach ($toStart as $requestId => $lease) {
            if (isset($started[$lease->deliveryId])) {
                $this->startedIds[$lease->deliveryId] = true;
                $this->started++;
            } else {
                $this->done[$lease->deliveryId] = true;   // the withheld result is not ours to write
                $this->lost++;
                $withheld[] = $requestId;
            }
        }

        return $withheld;
    }

    /**
     * Forget the deliveries of the chunk just finished (their outcomes are stored): one relay run can work through
     * millions of deliveries with flat memory.
     */
    public function release(): void
    {
        if ($this->unsaved === [] && $this->buffered === []) {
            $this->leases = $this->effects = $this->done = $this->startedIds = [];
        }
    }

    /**
     * Retry the writes that failed. Throws when one still cannot be saved: the job must not be acknowledged as if
     * the outcome were stored (the lease then expires and the ledger applies the unknown-outcome rule).
     */
    public function flush(): void
    {
        $this->flushWindow();
        foreach ($this->unsaved as $deliveryId => [$lease, $result, $budget]) {
            $this->noted($deliveryId, $result, $this->ledger->record($lease, $result, $budget));
            unset($this->unsaved[$deliveryId]);
        }
    }

    public function attemptFinished(AttemptReport $attempt): void {}

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void {}

    public function guardTransition(GuardTransition $transition): void {}

    public function providerThrottled(BindingRef $binding, string $accountKey, \DateTimeImmutable $until): void {}

    private function noted(string $deliveryId, SendResult $result, Recording $recording): void
    {
        if ($recording->isIgnored()) {
            $this->ignored++;

            return;
        }
        $this->byStatus[$result->status->value] = ($this->byStatus[$result->status->value] ?? 0) + 1;

        $effectId = $this->effects[$deliveryId] ?? null;
        if ($effectId !== null && $result->status === SendStatus::Deferred && $result->nextAttemptAt !== null) {
            $current = $this->deferredUntil[$effectId] ?? null;
            $this->deferredUntil[$effectId] = $current === null || $result->nextAttemptAt < $current ? $result->nextAttemptAt : $current;
        }
    }
}
