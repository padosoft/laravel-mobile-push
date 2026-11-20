<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

/**
 * What the final decision asks to do with the destination registration.
 */
enum DestinationAction: string
{
    case None = 'none';
    /** Routing suspicion (wrong environment, topic or sender): suspend the registration, do not delete it. */
    case Quarantine = 'quarantine';
    /** The provider states the registration is gone (only valid for the generation that was attempted). */
    case Invalidate = 'invalidate';
}
