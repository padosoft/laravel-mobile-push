<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

/** What the environment probe did with one quarantined device. */
enum ProbeOutcome: string
{
    /** The other environment accepted the push: the registration was re-labelled and reactivated (new generation). */
    case Relabelled = 'relabelled';
    /** The other environment rejected the token too: the device stays quarantined. */
    case StillRejected = 'still_rejected';
    /** Any other answer: the device was left untouched. */
    case Untouched = 'untouched';
    /** Accepted, but a registration of the same token already exists in the other environment: nothing was changed. */
    case Conflict = 'conflict';
    /** The device changed (re-registered, no longer quarantined) while the probe ran: nothing was changed. */
    case Changed = 'changed';
    /** Simulation mode: no request was sent, by design. */
    case NotSent = 'not_sent';
    /** Safe mode refused the probe (live sending outside production is not authorised for this binding or device). */
    case Blocked = 'blocked';
    /** No (or no unique) connection of the same app in the other environment. */
    case NoConnection = 'no_connection';
    /** The provider refused the credential: the whole run stopped here. */
    case AuthenticationError = 'authentication_error';
    /** The run stopped before this device. */
    case NotContacted = 'not_contacted';
    /** The stored token could not be read. */
    case Unreadable = 'unreadable';

    /** True when the outcome means the operator should look (it is not a clean "nothing to do"). */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Untouched, self::Blocked, self::Conflict, self::Changed, self::NoConnection, self::NotContacted, self::Unreadable, self::AuthenticationError => true,
            default => false,
        };
    }
}
