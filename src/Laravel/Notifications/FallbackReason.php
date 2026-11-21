<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/** Why FallbackRules decided as it did. */
enum FallbackReason: string
{
    /** Fallback: the notifiable has no eligible destination at all. */
    case NoDestination = 'no_destination';
    /** Fallback: every result is terminal, none accepted, at least one known not accepted. */
    case NotAccepted = 'not_accepted';

    case NoDestinationDisabled = 'no_destination_disabled';
    case UnknownAudience = 'unknown_audience';
    case Undecided = 'undecided';
    case Accepted = 'accepted';
    case OutcomeUnknown = 'outcome_unknown';
    case SuppressedByPolicy = 'suppressed_by_policy';
    case Simulated = 'simulated';
    case NothingKnownRefused = 'nothing_known_refused';
}
