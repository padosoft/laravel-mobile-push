<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

/** What DeviceOutcomeApplier did (or why it did nothing), so the caller can dispatch events. */
enum DeviceChange: string
{
    case Quarantined = 'quarantined';
    case Invalidated = 'invalidated';
    case NoAction = 'no_action';
    case UnknownOutcome = 'unknown_outcome';
    case RowMissing = 'row_missing';
    case GenerationChanged = 'generation_changed';
    case StaleTimestamp = 'stale_timestamp';
    case AlreadyInvalidated = 'already_invalidated';
    case AlreadyQuarantined = 'already_quarantined';

    public function changed(): bool
    {
        return $this === self::Quarantined || $this === self::Invalidated;
    }
}
