<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Cache\Repository;
use Psr\Clock\ClockInterface;

/**
 * At most one pending follow-up relay per effect, and a bounded number per scope.
 *
 * A marker in the cache holds the time of the follow-up already scheduled for (scope, effect). A new request is
 * dropped when the pending one runs no later; an earlier request replaces the marker and is dispatched (the later
 * job then finds nothing due, or work that became due, and is harmless). The follow-up job clears the marker when it
 * starts, only if the marker is still its own. Should a marker or a job be lost, the sweeper still finds the due
 * deliveries: the marker is an optimisation of the queue, never the safety net.
 *
 * Thundering herd (W5-P): when many effects of one scope are deferred to about the same instant (a provider
 * throttle answered to 10,000 small transactional effects), one job per effect would put 10,000 relay jobs on the
 * queue for the same second, each paying a claim, a reclaim and an expiry pass for one row. Per scope and per time
 * bucket (`queue.follow_up_bucket` seconds, aligned to the clock), the first MAX_EFFECT_FOLLOW_UPS effects keep
 * their own precise follow-up; every further effect is folded into ONE scope-wide relay for that bucket, run at the
 * end of the bucket: never before a provider's minimum delay, at most one bucket late. A bucket of 0 disables the
 * folding (one job per effect, as before).
 */
final readonly class RelayFollowUps
{
    public const PREFIX = 'mobile-push:relay-follow-up:';

    /** Precise per-effect follow-ups allowed per scope, queue and bucket before the rest share one scope-wide relay. */
    public const MAX_EFFECT_FOLLOW_UPS = 20;

    public function __construct(
        private Repository $cache,
        private SendJobDispatcher $jobs,
        private ClockInterface $clock,
        private QueueSettings $settings,
    ) {}

    /** A null effect id schedules a scope-wide relay (the sweeper's). Returns true when a job was dispatched. */
    public function schedule(string $scope, ?string $effectId, \DateTimeImmutable $at, ?string $queue = null): bool
    {
        $now = $this->clock->now();
        $delay = FollowUpPlanner::delaySeconds($at, $now);
        $runAt = $now->getTimestamp() + $delay;

        $bucket = $this->settings->followUpBucket;
        if ($effectId !== null && $bucket > 0 && $delay > 0) {
            $end = intdiv($runAt + $bucket - 1, $bucket) * $bucket;   // end of the clock-aligned bucket holding runAt
            if (! $this->underBucketLimit($scope, $queue, $end, $end - $now->getTimestamp())) {
                return $this->dispatchMarked(self::bucketKey($scope, $queue, $end), $scope, null, $end, $end - $now->getTimestamp(), $queue);
            }
        }

        return $this->dispatchMarked(self::key($scope, $effectId), $scope, $effectId, $runAt, $delay, $queue);
    }

    private function dispatchMarked(string $key, string $scope, ?string $effectId, int $runAt, int $delay, ?string $queue): bool
    {
        // A marker outlives its job by little: if the job is lost, the next sweep may dispatch again soon after.
        $ttl = $delay + $this->settings->timeout + 60;

        if (! $this->cache->add($key, $runAt, $ttl)) {
            $pending = $this->cache->get($key);
            if (is_numeric($pending) && (int) $pending <= $runAt) {
                return false;
            }
            $this->cache->put($key, $runAt, $ttl);
        }

        $this->jobs->dispatchNow(new RelayDeliveriesJob($scope, $effectId, $runAt, $this->settings->timeout), null, $queue, $delay);

        return true;
    }

    /** Counts this effect follow-up against its scope's bucket; false once the bucket already has its share. */
    private function underBucketLimit(string $scope, ?string $queue, int $end, int $ttl): bool
    {
        $key = self::PREFIX.'count:'.hash('sha256', $scope."\0".($queue ?? '')."\0".$end);
        try {
            $this->cache->add($key, 0, $ttl + $this->settings->timeout + 60);
            $count = $this->cache->increment($key);
        } catch (\Throwable) {
            return true;   // no counter: precise follow-ups, as without the folding
        }

        return ! is_numeric($count) || (int) $count <= self::MAX_EFFECT_FOLLOW_UPS;
    }

    /** Called by a follow-up job when it starts. */
    public function started(string $scope, ?string $effectId, int $runAt): void
    {
        $key = self::key($scope, $effectId);
        $pending = $this->cache->get($key);
        if (is_numeric($pending) && (int) $pending === $runAt) {
            $this->cache->forget($key);
        }
    }

    public static function key(string $scope, ?string $effectId): string
    {
        return self::PREFIX.hash('sha256', $scope."\0".($effectId ?? '*'));
    }

    /** Marker of the scope-wide relay that folds the follow-ups of one bucket (see the class comment). */
    public static function bucketKey(string $scope, ?string $queue, int $end): string
    {
        return self::PREFIX.'bucket:'.hash('sha256', $scope."\0".($queue ?? '')."\0".$end);
    }
}
