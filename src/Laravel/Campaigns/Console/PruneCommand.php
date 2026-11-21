<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Illuminate\Database\Query\Builder;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;

final class PruneCommand extends CampaignCommand
{
    protected $signature = 'mobile-push:campaign:prune
        {--days= : Delete finished campaigns older than this many days (default: mobile-push.campaigns.retention_days)}
        {--scope= : Only this tenant scope (default: every scope)}
        {--pretend : Only count what would be deleted; change nothing}
        {--json : Print the result as JSON}';

    protected $description = 'Delete finished campaign rows older than the retention; ledger rows prune themselves (exit 0 done; 1 refused; 2 invalid input)';

    protected function perform(): int
    {
        $days = $this->intOption('days', 1, 36_500) ?? $this->settings()->retentionDays;
        $scope = $this->optionString('scope') === null ? null : $this->scope();
        $pretend = (bool) $this->option('pretend');

        $count = $pretend ? $this->count($days, $scope) : $this->manager()->prune($days, $scope);

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'pretend' => $pretend, 'days' => $days, 'scope' => $scope, $pretend ? 'would_delete' : 'deleted' => $count]);

            return self::EXIT_OK;
        }
        $this->line(sprintf('%s %d finished campaign(s) older than %d day(s)%s.', $pretend ? 'Would delete' : 'Deleted', $count, $days, $scope === null ? ' in every scope' : ' in scope '.$scope));
        if ($pretend) {
            $this->line('--pretend: nothing was changed.');
        }

        return self::EXIT_OK;
    }

    /** Mirrors CampaignStore::pruneFinished(), without deleting. */
    private function count(int $days, ?string $scope): int
    {
        $store = $this->store();
        $cutoff = $store->nowMs() - $days * 86_400_000;

        return $store->connection()->table($this->settings()->table)
            ->whereIn('status', CampaignStatus::values(CampaignStatus::Completed, CampaignStatus::Cancelled, CampaignStatus::Failed))
            ->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', $scope))
            ->where(static fn (Builder $q): Builder => $q->where('finished_at', '<', $cutoff)
                ->orWhere(static fn (Builder $q): Builder => $q->whereNull('finished_at')->where('updated_at', '<', $cutoff)))
            ->count();
    }
}
