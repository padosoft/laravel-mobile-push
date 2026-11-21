<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/**
 * Optional capability of a GuardStore: tells an unreachable backend apart from a contended one.
 *
 * Both look the same through GuardStore (reads return null, compareAndSet returns false). The difference decides
 * what the guards do: when the store is unavailable they fail open (the send goes on, unprotected by shared state);
 * when it is alive but contended they refuse, and the destination is deferred.
 */
interface ReportsAvailability
{
    /** False while the backend is known to be unusable (unreachable, or unable to write atomically). */
    public function available(): bool;
}
