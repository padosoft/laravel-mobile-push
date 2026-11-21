<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Cache\Repository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

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
 *
 * Fail-open cache: the markers are an optimisation, so an unreachable store never stops a follow-up. A duplicate relay
 * job is harmless (the ledger's claim makes a second relay a no-op), a missing one is not: when the store throws, the
 * follow-up is dispatched WITHOUT a marker (no dedupe), started() lets the job run, the bucket counter is skipped
 * (precise follow-ups). After a failure this instance leaves the store alone for RETRY_AFTER_SECONDS (no hammering a
 * dead backend) and logs one warning (class name only, never a key or a value) at most once a minute.
 */
final class RelayFollowUps
{
    public const PREFIX = 'mobile-push:relay-follow-up:';

    /** Precise per-effect follow-ups allowed per scope, queue and bucket before the rest share one scope-wide relay. */
    public const MAX_EFFECT_FOLLOW_UPS = 20;

    /** After a store failure, the store is not called again for this long (as CacheGuardStore rests its backend). */
    public const RETRY_AFTER_SECONDS = 5;

    private const LOG_EVERY_SECONDS = 60;

    /** Unix seconds until which the store is not called (0: available). */
    private int $restUntil = 0;

    private ?int $loggedAt = null;

    public function __construct(
        private readonly Repository $cache,
        private readonly SendJobDispatcher $jobs,
        private readonly ClockInterface $clock,
        private readonly QueueSettings $settings,
        private readonly ?LoggerInterface $logger = null,
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

        // Without a store the follow-up is dispatched unmarked: a duplicate relay is a no-op, a missing one is not.
        $pending = $this->cached(function () use ($key, $runAt, $ttl): bool {
            if ($this->cache->add($key, $runAt, $ttl)) {
                return false;
            }
            $pending = $this->cache->get($key);
            if (is_numeric($pending) && (int) $pending <= $runAt) {
                return true;
            }
            $this->cache->put($key, $runAt, $ttl);

            return false;
        }, false);
        if ($pending) {
            return false;
        }

        $this->jobs->dispatchNow(new RelayDeliveriesJob($scope, $effectId, $runAt, $this->settings->timeout), null, $queue, $delay);

        return true;
    }

    /** Counts this effect follow-up against its scope's bucket; false once the bucket already has its share. */
    private function underBucketLimit(string $scope, ?string $queue, int $end, int $ttl): bool
    {
        $key = self::PREFIX.'count:'.hash('sha256', $scope."\0".($queue ?? '')."\0".$end);
        // No counter (store down): precise follow-ups, as without the folding.
        $count = $this->cached(function () use ($key, $ttl): mixed {
            $this->cache->add($key, 0, $ttl + $this->settings->timeout + 60);

            return $this->cache->increment($key);
        }, null);

        return ! is_numeric($count) || (int) $count <= self::MAX_EFFECT_FOLLOW_UPS;
    }

    /** Called by a follow-up job when it starts. */
    public function started(string $scope, ?string $effectId, int $runAt): void
    {
        $key = self::key($scope, $effectId);
        // Store down: the job goes ahead; the marker (if any) expires with its TTL.
        $this->cached(function () use ($key, $runAt): null {
            $pending = $this->cache->get($key);
            if (is_numeric($pending) && (int) $pending === $runAt) {
                $this->cache->forget($key);
            }

            return null;
        }, null);
    }

    /**
     * Runs one store interaction; on a throwing store returns $fallback, rests the store and logs (rate limited).
     *
     * @template T
     *
     * @param  \Closure(): T  $step
     * @param  T  $fallback
     * @return T
     */
    private function cached(\Closure $step, mixed $fallback): mixed
    {
        $now = $this->clock->now()->getTimestamp();
        if ($now < $this->restUntil) {
            return $fallback;
        }

        try {
            return $step();
        } catch (\Throwable $e) {
            $this->restUntil = $now + self::RETRY_AFTER_SECONDS;
            if ($this->loggedAt === null || $now - $this->loggedAt >= self::LOG_EVERY_SECONDS) {
                $this->loggedAt = $now;
                try {
                    $this->logger?->warning('mobile-push: the cache store is unreachable; relay follow-ups are dispatched without their dedupe marker (a duplicate relay is a no-op).', [
                        'exception' => $e::class,
                    ]);
                } catch (\Throwable) {
                    // a failing logger is ignored as well
                }
            }

            return $fallback;
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
