<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;

/**
 * Housekeeping, meant for a daily scheduler run:
 *  - batch expiry: open activities past `local_expires_at` become `expired`. This only tidies the table (state,
 *    lists, cleanup of channels): sending already stops at the expiry without it, because every transition checks
 *    `local_expires_at` against the clock (TransitionRules) and marks the row `expired` itself. The device may still
 *    show the activity: Apple ends it on its own schedule, and only the app can report that;
 *  - closed activities (and invalidated push-to-start tokens) older than the retention are deleted. Nothing else
 *    deletes them, so the pruner still needs to be scheduled.
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
