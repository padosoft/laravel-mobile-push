<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

/** Which records the database sink persists. */
enum DatabaseMode: string
{
    case All = 'all';
    /** Only outcomes that are not routine successes (anything but accepted / simulated / validated). */
    case Failures = 'failures';
    /** Every non-routine outcome, plus a random fraction of the routine successes. */
    case Sampled = 'sampled';
}
