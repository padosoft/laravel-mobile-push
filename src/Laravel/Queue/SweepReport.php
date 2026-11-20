<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** What one sweep did (counters and scope names only). */
final readonly class SweepReport
{
    /**
     * @param  list<string>  $scopes  scopes visited (they had due work or expired leases)
     * @param  list<string>  $relaysDispatched  scopes for which a relay job was dispatched
     */
    public function __construct(
        public array $scopes = [],
        /** Leases lost before any request left: due again. */
        public int $requeued = 0,
        /** Leases lost after the request may have left, retry allowed (replaceable): due again, budget marked. */
        public int $retriedAfterUnknown = 0,
        /** Leases lost after the request may have left, no retry: ended as OutcomeUnknown. */
        public int $endedUnknown = 0,
        public int $expired = 0,
        public array $relaysDispatched = [],
    ) {}
}
