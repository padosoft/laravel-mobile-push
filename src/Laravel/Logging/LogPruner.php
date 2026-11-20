<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Psr\Clock\ClockInterface;

/** Deletes log rows older than the retention, in chunks (the artisan command is another task). */
final readonly class LogPruner
{
    public function __construct(private ClockInterface $clock) {}

    /**
     * @return int rows deleted (or that would be, in a dry run)
     */
    public function prune(int $retentionDays, int $chunk = 1000, bool $dryRun = false): int
    {
        $cutoff = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-'.max(0, $retentionDays).' days');

        return $this->pruneBefore($cutoff, $chunk, $dryRun);
    }

    public function pruneBefore(\DateTimeImmutable $cutoff, int $chunk = 1000, bool $dryRun = false): int
    {
        $stamp = $cutoff->setTimezone(new \DateTimeZone('UTC'))->format(DatabaseSink::DATE_FORMAT);
        $model = new PushLog;
        $key = $model->getKeyName();

        if ($dryRun) {
            return $model->newQuery()->where('created_at', '<', $stamp)->count();
        }

        $chunk = max(1, $chunk);
        $total = 0;

        do {
            $ids = $model->newQuery()->where('created_at', '<', $stamp)->orderBy($key)->limit($chunk)->pluck($key)->all();

            if ($ids === []) {
                break;
            }

            $total += $model->newQuery()->whereIn($key, $ids)->toBase()->delete();
        } while (count($ids) >= $chunk);

        return $total;
    }
}
