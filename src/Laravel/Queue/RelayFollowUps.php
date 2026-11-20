<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Cache\Repository;
use Psr\Clock\ClockInterface;

/**
 * At most one pending follow-up relay per effect.
 *
 * A marker in the cache holds the time of the follow-up already scheduled for (scope, effect). A new request is
 * dropped when the pending one runs no later; an earlier request replaces the marker and is dispatched (the later
 * job then finds nothing due, or work that became due, and is harmless). The follow-up job clears the marker when it
 * starts, only if the marker is still its own. Should a marker or a job be lost, the sweeper still finds the due
 * deliveries: the marker is an optimisation of the queue, never the safety net.
 */
final readonly class RelayFollowUps
{
    public const PREFIX = 'mobile-push:relay-follow-up:';

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
        $key = self::key($scope, $effectId);
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
}
