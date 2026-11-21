<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Core\Ledger\Ledger;

/**
 * Deletes finished deliveries (and the effects left empty) older than the retention. Schedulable: a refusal on the
 * light profile is a notice with exit code 0, because there is nothing to prune there.
 *
 * Config: mobile-push.ledger.retention_days (default 30).
 */
final class PruneDeliveriesCommand extends OpsCommand
{
    public const DEFAULT_DAYS = 30;

    protected $signature = 'mobile-push:deliveries:prune
        {--days= : Keep finished deliveries this many days (default: mobile-push.ledger.retention_days, 30)}
        {--scope= : Prune only this scope; default is every scope}
        {--pretend : Count what would be deleted without deleting}
        {--json : Print the summary as JSON}';

    protected $description = 'Delete finished ledger deliveries and effects older than the retention';

    public function handle(): int
    {
        if (config('mobile-push.delivery.profile', 'light') !== 'durable') {
            $this->components->warn('Skipped: the light delivery profile keeps no ledger, so there is nothing to prune. (MOBILE_PUSH_DELIVERY_PROFILE=durable enables the ledger.)');

            return self::SUCCESS;
        }

        $configured = config('mobile-push.ledger.retention_days', self::DEFAULT_DAYS);
        $days = $this->intOption('days') ?? (is_numeric($configured) ? max(0, (int) $configured) : self::DEFAULT_DAYS);
        $scope = $this->stringOption('scope');
        $pretend = (bool) $this->option('pretend');

        if ($pretend) {
            $queries = new LedgerQueries($this->laravel);
            if (! $queries->available()) {
                throw new UsageError('--pretend needs the database ledger.');
            }
            $count = $queries->prunable($days * 86_400, $scope);
        } else {
            $result = $this->laravel->make(Ledger::class)->prune($days * 86_400, $scope);
            $count = ['deliveries' => $result->deliveries, 'effects' => $result->effects];
        }

        $this->emitSummary($pretend ? 'Pretend: nothing was deleted' : 'Prune finished', [
            'retention_days' => $days,
            'scope' => $scope ?? 'all',
            'pretend' => $pretend,
            ($pretend ? 'would_delete' : 'deleted') => $count,
        ]);

        return self::SUCCESS;
    }
}
