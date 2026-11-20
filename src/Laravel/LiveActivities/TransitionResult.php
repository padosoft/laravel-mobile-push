<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * The result of one update or end (of an activity) or one publication (on a broadcast channel).
 *
 * `$result` is the pipeline's SendResult when a request was attempted (the report has been consumed); it is null
 * when nothing was attempted. A broadcast accepted by APNs is ONE publication (`$publications` = 1): there is no
 * per-device result, delivery count, receipt or fallback behind it.
 */
final readonly class TransitionResult
{
    public function __construct(
        public TransitionOutcome $outcome,
        public SendStatus $status,
        public Reason $reason,
        public ?SendResult $result,
        /** The row (PushActivity or BroadcastChannel) as of the last write. */
        public ?Model $row,
        /** The ticket number of the transition. */
        public ?int $seq = null,
        /** Unix second carried by the request (the current second when it was built; never a future one). */
        public ?int $timestamp = null,
        public ?Priority $priority = null,
        /** High priority was asked for, the hourly limiter sent it at priority 5. */
        public bool $downgraded = false,
        public ?\DateTimeImmutable $nextAttemptAt = null,
        public bool $broadcast = false,
    ) {}

    public function sent(): bool
    {
        return $this->outcome === TransitionOutcome::Sent;
    }

    public function isAccepted(): bool
    {
        return $this->status === SendStatus::Accepted;
    }

    /** Broadcast publications accepted by APNs by this call: 0 or 1, never a number of devices. */
    public function publications(): int
    {
        return $this->broadcast && $this->isAccepted() ? 1 : 0;
    }
}
