<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * What happens next for one destination, as decided by RetryPlanner.
 *
 * `$status` is the public status to report now: Deferred (another attempt at `$nextAttemptAt`) or a terminal one.
 * `$budget` is the updated budget: the caller MUST persist it and pass it to the next plan() call.
 * The destination and credential actions of the classification stay on `$decision`: the planner never
 * changes them (an invalidation or a token refresh applies whatever the retry outcome).
 */
final readonly class RetryPlan
{
    public function __construct(
        public SendStatus $status,
        public Reason $reason,
        public RetryBudget $budget,
        public ?\DateTimeImmutable $nextAttemptAt = null,
        /** Delay before the next attempt, in milliseconds (null when terminal). */
        public ?int $delayMs = null,
        /** The wait is short enough (RetryPolicy::$inlineThresholdMs) to be done in-process instead of re-queued. */
        public bool $inline = false,
        /** The classification that led here (null for a pre-attempt expiry check). */
        public ?Decision $decision = null,
    ) {}

    public function shouldRetry(): bool
    {
        return $this->status === SendStatus::Deferred;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /** The provider/transport reason that caused this plan, before any budget wrapping. */
    public function cause(): ?Reason
    {
        return $this->decision?->reason;
    }
}
