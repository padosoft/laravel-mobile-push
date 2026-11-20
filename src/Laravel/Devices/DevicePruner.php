<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Psr\Clock\ClockInterface;

/** Deletes dead registrations in bounded chunks. */
final readonly class DevicePruner
{
    private const CHUNK = 500;

    public function __construct(private ClockInterface $clock) {}

    /**
     * @param  int|null  $invalidatedOlderThanDays  delete invalidated rows whose state changed earlier than this (null = skip)
     * @param  int|null  $inactiveOlderThanDays  delete rows (any state) not seen for this long (null = skip)
     */
    public function prune(?int $invalidatedOlderThanDays, ?int $inactiveOlderThanDays, bool $dryRun = false): PruneReport
    {
        $now = CarbonImmutable::instance($this->clock->now())->setTimezone(date_default_timezone_get());
        $invalidated = 0;
        $inactive = 0;

        if ($invalidatedOlderThanDays !== null) {
            $cutoff = $now->subDays($invalidatedOlderThanDays);
            $invalidated = $this->delete($dryRun, static fn ($q) => $q
                ->where('state', DeviceState::Invalidated->value)
                ->where('state_changed_at', '<', $cutoff));
        }

        if ($inactiveOlderThanDays !== null) {
            $cutoff = $now->subDays($inactiveOlderThanDays);
            $inactive = $this->delete($dryRun, static fn ($q) => $q->where(static function ($w) use ($cutoff): void {
                $w->where('last_seen_at', '<', $cutoff)
                    ->orWhere(static fn ($n) => $n->whereNull('last_seen_at')->where('registered_at', '<', $cutoff));
            }));
        }

        return new PruneReport($invalidated, $inactive, $dryRun);
    }

    /** @param  \Closure(Builder<PushDevice>): mixed  $constrain */
    private function delete(bool $dryRun, \Closure $constrain): int
    {
        $model = new (PushDevice::modelClass());
        $key = $model->getKeyName();

        if ($dryRun) {
            $query = $model->newQuery();
            $constrain($query);

            return $query->count();
        }

        $total = 0;
        do {
            $query = $model->newQuery();
            $constrain($query);
            $ids = $query->orderBy($key)->limit(self::CHUNK)->pluck($key)->all();

            if ($ids === []) {
                break;
            }

            $total += (int) $model->newQuery()->whereIn($key, $ids)->toBase()->delete();
        } while (count($ids) === self::CHUNK);

        return $total;
    }
}
