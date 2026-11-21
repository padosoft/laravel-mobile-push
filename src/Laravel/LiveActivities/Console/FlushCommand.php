<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;

final class FlushCommand extends LiveActivityCommand
{
    protected $signature = 'mobile-push:live-activity:flush
        {--limit=100 : How many pending transitions at most (per kind), 1 to 10000}
        {--json : Print the result as JSON}';

    protected $description = 'Send the Live Activity updates that are due: coalesced same-second updates and pending retries';

    protected function perform(): int
    {
        $limit = Input::positiveInt('limit', $this->option('limit'), 1, 10_000);
        $binding = $this->binding();
        $decision = $this->safeMode()->decide($binding, OperationKind::Send);

        if ($decision->isSimulated() || $decision->isBlocked()) {
            $due = $this->dueCount();

            return $this->reportDecision($decision, sprintf('send %d pending transition(s) that are due', $due));
        }

        $results = $this->manager()->flushDue($limit);
        $counts = [];
        foreach ($results as $result) {
            $key = $result->outcome->value.'/'.$result->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        $accepted = count(array_filter($results, static fn ($r): bool => $r->isAccepted()));

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'flushed' => count($results), 'accepted' => $accepted, 'by_outcome' => $counts]);

            return self::EXIT_OK;
        }

        if ($results === []) {
            $this->line('Nothing is due.');

            return self::EXIT_OK;
        }

        $this->line(sprintf('Flushed %d pending transition(s); %d accepted by APNs.', count($results), $accepted));
        foreach ($counts as $key => $count) {
            $this->line("  {$key}: {$count}");
        }

        return self::EXIT_OK;
    }

    private function dueCount(): int
    {
        $now = $this->now()->getTimestamp();
        $count = 0;
        foreach ([PushActivity::blank(), BroadcastChannel::blank()] as $model) {
            $count += $model->newQuery()->whereNotNull('pending_seq')->where('pending_due_at', '<=', $now)->count();
        }

        return $count;
    }
}
