<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Laravel\Logging\LogPruner;
use Padosoft\MobilePush\Laravel\Logging\LoggingModule;

/**
 * Deletes send-log rows older than the retention (database sink only). Schedulable; without the database sink it is a
 * notice with exit code 0.
 *
 * Config: mobile-push.log.database.retention_days (default 30).
 */
final class PruneLogsCommand extends OpsCommand
{
    public const DEFAULT_DAYS = 30;

    protected $signature = 'mobile-push:logs:prune
        {--days= : Keep log rows this many days (default: log.database.retention_days, 30)}
        {--pretend : Count what would be deleted without deleting}
        {--json : Print the summary as JSON}';

    protected $description = 'Delete database send-log rows older than the retention';

    public function handle(): int
    {
        if (! in_array('database', LoggingModule::sinkNames($this->laravel), true)) {
            $this->components->warn('Skipped: the database log sink is not enabled (add "database" to MOBILE_PUSH_LOG_SINKS), so there is no log table to prune.');

            return self::SUCCESS;
        }

        $configured = config('mobile-push.log.database.retention_days', self::DEFAULT_DAYS);
        $days = $this->intOption('days') ?? (is_numeric($configured) ? max(0, (int) $configured) : self::DEFAULT_DAYS);
        $pretend = (bool) $this->option('pretend');

        $rows = $this->laravel->make(LogPruner::class)->prune($days, 1000, $pretend);

        $this->emitSummary($pretend ? 'Pretend: nothing was deleted' : 'Prune finished', [
            'retention_days' => $days,
            'pretend' => $pretend,
            ($pretend ? 'would_delete' : 'deleted') => ['log_rows' => $rows],
        ]);

        return self::SUCCESS;
    }
}
