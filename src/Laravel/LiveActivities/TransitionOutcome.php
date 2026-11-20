<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

enum TransitionOutcome: string
{
    /** A request was handed to the send pipeline (see TransitionResult::$status for what APNs answered). */
    case Sent = 'sent';
    /** Nothing was sent now: the transition is pending (same-second coalescing, or a planned retry). */
    case Deferred = 'deferred';
    /** Nothing was sent and nothing will be: the activity is closed, the ticket is stale, or no token is usable. */
    case Skipped = 'skipped';
}
