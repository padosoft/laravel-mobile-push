<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** What happened to a write made under a lease. Ignored writes are expected, never errors. */
enum Recording: string
{
    case Recorded = 'recorded';
    /** Another worker, a reclaim or a cancel changed the row after this lease was taken. */
    case IgnoredStaleLease = 'ignored_stale_lease';
    /** The row already has its definitive outcome. */
    case IgnoredFinal = 'ignored_final';
    /** No such delivery in this scope. */
    case NotFound = 'not_found';

    public function isRecorded(): bool
    {
        return $this === self::Recorded;
    }

    public function isIgnored(): bool
    {
        return $this !== self::Recorded;
    }
}
