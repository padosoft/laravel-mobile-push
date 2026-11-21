<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

/** What the frequency gate does when the counters cannot be read or written. */
enum StoreFailurePolicy: string
{
    /** Fail open: the destinations proceed (a cache outage never silences notifications). */
    case Allow = 'allow';

    /** Fail closed: the destinations are skipped (a cache outage never causes a flood). */
    case Deny = 'deny';
}
