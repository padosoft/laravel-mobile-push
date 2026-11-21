<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * A remote start: one SendResult per push-to-start token, and a `requested` activity row for each start that
 * actually reached the provider (accepted, unknown, or known not accepted: Rejected / Failed, then closed).
 * A start is never retried by the server: an unknown outcome stays `requested` with start_status `outcome_unknown`.
 */
final readonly class StartResult
{
    /**
     * @param  array<int, PushActivity>  $activities  keyed by the position of their result in `$results`; a start that
     *                                                did not leave the server (Simulated, Skipped, Expired, Deferred) has
     *                                                no row, so its position is absent
     * @param  list<SendResult>  $results  one per push-to-start token
     */
    public function __construct(
        public array $activities,
        public array $results,
    ) {}

    public function count(): int
    {
        return count($this->results);
    }

    public function countOf(SendStatus $status): int
    {
        return count(array_filter($this->results, static fn (SendResult $r): bool => $r->status === $status));
    }

    public function accepted(): int
    {
        return $this->countOf(SendStatus::Accepted);
    }
}
