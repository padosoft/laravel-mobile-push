<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;

/** The fields of an activity (or channel) row the transition rules read, as of one row version. */
final readonly class RowSnapshot
{
    public function __construct(
        public ActivityState $state,
        /** A usable destination exists (an active update token, or an active channel). */
        public bool $deliverable,
        public int $appliedSeq,
        public ?int $pendingSeq,
        public ?int $lastTimestamp,
        public ?int $hpWindowStartedAt = null,
        public int $hpCount = 0,
    ) {}
}
