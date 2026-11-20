<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/** What OpsAlerter did with one alert condition. */
enum AlertOutcome: string
{
    /** Handed to the notification channels. */
    case Sent = 'sent';
    /** Written to the log instead of sent (simulate mode, no route configured, or delivery failed). */
    case Logged = 'logged';
    /** The same alert key is inside its cooldown. */
    case Suppressed = 'suppressed';
    /** Alerts are disabled. */
    case Disabled = 'disabled';
}
