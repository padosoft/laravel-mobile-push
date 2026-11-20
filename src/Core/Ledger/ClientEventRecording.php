<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** What happened to a client-reported event. A duplicate is expected and harmless, never an error. */
enum ClientEventRecording: string
{
    case Recorded = 'recorded';
    /** This delivery already has this event: the first report wins, nothing changed. */
    case Duplicate = 'duplicate';
    /** No such delivery in this scope. */
    case NotFound = 'not_found';
    /** The delivery never reached a state a client could have seen (not accepted, not unknown). */
    case NotEligible = 'not_eligible';

    public function isRecorded(): bool
    {
        return $this === self::Recorded;
    }

    /** True when the client can treat the report as done (recorded now or earlier). */
    public function isAcknowledged(): bool
    {
        return $this === self::Recorded || $this === self::Duplicate;
    }
}
