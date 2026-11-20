<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Durable profile: relays the due deliveries of one effect (or, from the sweeper, of a whole scope).
 *
 * Carries only ids, never a token or a message: everything is read again from the ledger. Running it twice, or
 * redelivering it after a worker died, is safe: deliveries are claimed under leases, outcomes are written once, and
 * an attempt that may have left is never treated as not sent.
 */
final class RelayDeliveriesJob implements ShouldQueue
{
    use Queueable;

    /** Safe to retry: a rerun claims again and never re-sends a delivery with a recorded or unknown outcome. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $scope,
        /** Null: every due delivery of the scope. */
        public readonly ?string $effectId = null,
        /** Set on a follow-up: the time it was scheduled for (clears its marker when it starts). */
        public readonly ?int $followUpAt = null,
        int $timeout = 120,
    ) {
        $this->timeout = $timeout;
    }

    public function handle(DeliveryRelay $relay, RelayFollowUps $followUps): void
    {
        if ($this->followUpAt !== null) {
            $followUps->started($this->scope, $this->effectId, $this->followUpAt);
        }

        $relay->run($this->scope, $this->effectId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_filter([
            'mobile-push',
            'relay',
            'scope:'.$this->scope,
            $this->effectId === null ? null : 'effect:'.$this->effectId,
        ]));
    }

    public function displayName(): string
    {
        return 'mobile-push relay '.$this->scope.($this->effectId === null ? ' (scope)' : ' '.$this->effectId);
    }
}
