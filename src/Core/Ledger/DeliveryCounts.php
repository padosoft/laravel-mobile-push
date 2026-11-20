<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** Counters by stored state (no personal data). */
final readonly class DeliveryCounts
{
    /** @var array<string, int> state value => count, every state present */
    public array $byState;

    /** @param array<string, int> $counts */
    public function __construct(array $counts = [])
    {
        $byState = [];
        foreach (DeliveryState::cases() as $state) {
            $byState[$state->value] = $counts[$state->value] ?? 0;
        }
        $this->byState = $byState;
    }

    public function of(DeliveryState $state): int
    {
        return $this->byState[$state->value];
    }

    public function total(): int
    {
        return array_sum($this->byState);
    }

    /** Waiting for a worker (pending + deferred). */
    public function waiting(): int
    {
        return $this->of(DeliveryState::Pending) + $this->of(DeliveryState::Deferred);
    }

    public function inFlight(): int
    {
        return $this->of(DeliveryState::Leased) + $this->of(DeliveryState::Sending);
    }

    public function terminal(): int
    {
        return $this->total() - $this->waiting() - $this->inFlight();
    }

    /** Known not accepted (rejected + failed + expired). An unknown outcome is NOT counted here. */
    public function notAccepted(): int
    {
        return $this->of(DeliveryState::Rejected) + $this->of(DeliveryState::Failed) + $this->of(DeliveryState::Expired);
    }
}
