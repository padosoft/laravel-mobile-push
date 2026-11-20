<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
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
 */
final class RelayRecorder implements SendObserver
{
    /** @var array<string, Lease> delivery id => lease, for the deliveries handed to the Sender */
    private array $leases = [];

    /** @var array<string, string> delivery id => effect id */
    private array $effects = [];

    /** @var array<string, true> */
    private array $done = [];

    /** @var array<string, array{0: Lease, 1: SendResult, 2: ?RetryBudget}> */
    private array $unsaved = [];

    /** @var array<string, \DateTimeImmutable> effect id => earliest next attempt of a deferred delivery */
    public array $deferredUntil = [];

    /** @var array<string, int> */
    public array $byStatus = [];

    public int $ignored = 0;

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

        try {
            $this->noted($deliveryId, $result, $this->ledger->record($lease, $result, $budget));
        } catch (\Throwable) {
            $this->unsaved[$deliveryId] = [$lease, $result, $budget];
        }
    }

    /**
     * Retry the writes that failed. Throws when one still cannot be saved: the job must not be acknowledged as if
     * the outcome were stored (the lease then expires and the ledger applies the unknown-outcome rule).
     */
    public function flush(): void
    {
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
