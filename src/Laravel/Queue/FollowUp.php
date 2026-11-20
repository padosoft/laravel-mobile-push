<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** One continuation job of a light send: its destinations (with budgets) and when it may run. */
final readonly class FollowUp
{
    /** @param  list<QueuedDestination>  $destinations */
    public function __construct(
        public array $destinations,
        /** The latest next attempt of the group: the job must not run earlier. */
        public \DateTimeImmutable $at,
        public int $delaySeconds,
    ) {}
}
