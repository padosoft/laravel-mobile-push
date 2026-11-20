<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

/** What applying a provider outcome did to a token row. */
enum TokenChange: string
{
    case NoAction = 'no_action';
    /** An unknown outcome never changes a token. */
    case UnknownOutcome = 'unknown_outcome';
    case RowMissing = 'row_missing';
    /** The outcome is about an older generation: the current token is left alone. */
    case GenerationChanged = 'generation_changed';
    case Quarantined = 'quarantined';
    case Invalidated = 'invalidated';
    case AlreadyClosed = 'already_closed';
}
