<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

/** What ChannelRegistry::sync() found and changed for one app and environment. */
final readonly class SyncReport
{
    /**
     * @param  list<string>  $adopted  channel ids found at APNs and attached to a local `unknown` row
     * @param  list<string>  $deletedLocally  channel ids no longer listed by APNs, marked `deleted`
     * @param  int  $neverCreated  `unknown` / `creating` rows resolved as never created (no orphan at APNs)
     * @param  list<string>  $orphans  channel ids at APNs that no local row claims and that could not be adopted safely
     */
    public function __construct(
        public array $adopted,
        public array $deletedLocally,
        public int $neverCreated,
        public array $orphans,
        public int $remoteCount,
    ) {}

    public function isConsistent(): bool
    {
        return $this->orphans === [];
    }
}
