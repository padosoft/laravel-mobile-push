<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;

/**
 * Housekeeping, meant for the scheduler:
 *  - activities past `local_expires_at` become `expired`: the server stops sending (the device may still show the
 *    activity: Apple ends it on its own schedule, and only the app can report that);
 *  - closed activities (and invalidated push-to-start tokens) older than the retention are deleted.
 */
final readonly class ActivityPruner
{
    public function __construct(
        private ActivityRegistry $registry,
        private LiveActivitiesConfig $config,
        private Rows $rows,
    ) {}

    /** @return array{expired: int, deleted: int} */
    public function prune(?int $closedOlderThanDays = null): array
    {
        $expired = 0;
        do {
            $batch = $this->registry->expireDue();
            $expired += $batch;
        } while ($batch === 500);

        $days = $closedOlderThanDays ?? $this->config->pruneClosedAfterDays;
        $deleted = $this->registry->deleteClosedBefore($this->rows->now()->subDays($days));

        return ['expired' => $expired, 'deleted' => $deleted];
    }
}
