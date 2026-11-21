<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/** What one planner run did (for logs and tests). */
enum SliceOutcome: string
{
    /** A slice was written and the checkpoint advanced. */
    case Written = 'written';
    /** The slice of this job was already written: only made sure the next job is queued. */
    case Continued = 'continued';
    /** Not planning (draft, paused, running, final, failed): nothing written. */
    case Stopped = 'stopped';
    /** Another planner holds a valid lease on the campaign. */
    case Busy = 'busy';
    /** Before its pacing slot: re-queued (or left to the sweeper). */
    case Early = 'early';
    /** An outdated job (the chain moved on without it). */
    case Stale = 'stale';
    /** The lease expired and another planner took over: this run's checkpoint was dropped. */
    case TakenOver = 'taken_over';
    /** A permanent error: the campaign is now failed (resumable). */
    case Failed = 'failed';
    /** No such campaign in this scope. */
    case Missing = 'missing';
}
