<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * A remote start: one `requested` activity row and one SendResult per push-to-start token it went to.
 * A start is never retried by the server: an unknown outcome stays `requested` with start_status `outcome_unknown`.
 */
final readonly class StartResult
{
    /**
     * @param  list<PushActivity>  $activities
     * @param  list<SendResult>  $results  same order as `$activities`
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
