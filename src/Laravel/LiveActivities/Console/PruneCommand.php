<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Laravel\LiveActivities\ActivityPruner;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushToStartToken;
use Padosoft\MobilePush\Laravel\LiveActivities\TokenState;

final class PruneCommand extends LiveActivityCommand
{
    protected $signature = 'mobile-push:live-activity:prune
        {--days= : Delete closed rows older than this many days (default: mobile-push.live_activities.prune.closed_after_days)}
        {--pretend : Only count what would be expired and deleted; change nothing}
        {--json : Print the result as JSON}';

    protected $description = 'Expire Live Activities past their local expiry and delete old closed rows';

    protected function perform(): int
    {
        $days = $this->optionString('days') === null ? $this->config()->pruneClosedAfterDays : Input::positiveInt('days', $this->option('days'), 0, 36_500);
        $pretend = (bool) $this->option('pretend');

        if ($pretend) {
            $report = $this->count($days);
        } else {
            $report = $this->laravel->make(ActivityPruner::class)->prune($days);
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'pretend' => $pretend, 'days' => $days] + $report);

            return self::EXIT_OK;
        }

        $this->line(sprintf(
            '%s %d activit%s past the local expiry (the server stops sending; the device may still show them) and %s %d closed row(s) older than %d day(s).',
            $pretend ? 'Would expire' : 'Expired', $report['expired'], $report['expired'] === 1 ? 'y' : 'ies',
            $pretend ? 'would delete' : 'deleted', $report['deleted'], $days,
        ));
        if ($pretend) {
            $this->line('--pretend: nothing was changed.');
        }

        return self::EXIT_OK;
    }

    /** @return array{expired: int, deleted: int} */
    private function count(int $days): array
    {
        $now = $this->now();
        $activities = PushActivity::blank();
        $tokens = PushToStartToken::blank();
        $cutoff = $now->modify("-{$days} days");

        $expired = $activities->newQuery()->open()->whereNotNull('local_expires_at')
            ->where('local_expires_at', '<=', $activities->fromDateTime($now))->count();
        $deleted = $activities->newQuery()
            ->whereIn('state', [ActivityState::Ended->value, ActivityState::Expired->value])
            ->where('closed_at', '<', $activities->fromDateTime($cutoff))->count()
            + $tokens->newQuery()->where('state', TokenState::Invalidated->value)
                ->where('state_changed_at', '<', $tokens->fromDateTime($cutoff))->count();

        return ['expired' => $expired, 'deleted' => $deleted];
    }
}
