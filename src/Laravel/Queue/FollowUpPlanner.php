<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/**
 * Groups deferred destinations of a light job into continuation jobs.
 *
 * Destinations are sorted by their next attempt and cut into buckets of at most `$bucketSeconds` between the first
 * and the last next attempt of the bucket. A bucket runs at its LATEST next attempt, never earlier: nobody is
 * retried before the provider's minimum delay, and nobody waits more than `$bucketSeconds` beyond it. The delay is
 * rounded up to whole seconds (queues delay in seconds).
 */
final readonly class FollowUpPlanner
{
    public function __construct(private int $bucketSeconds = 60)
    {
        if ($bucketSeconds < 0) {
            throw new \InvalidArgumentException('The follow-up bucket cannot be negative.');
        }
    }

    /**
     * @param  list<array{0: QueuedDestination, 1: \DateTimeImmutable}>  $deferred  destination with its next attempt
     * @return list<FollowUp>
     */
    public function plan(array $deferred, \DateTimeImmutable $now): array
    {
        if ($deferred === []) {
            return [];
        }

        usort($deferred, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        $plans = [];
        $bucket = [];
        $first = $last = null;
        foreach ($deferred as [$destination, $at]) {
            if ($first !== null && $last !== null && self::ms($at) - self::ms($first) > $this->bucketSeconds * 1000) {
                $plans[] = new FollowUp($bucket, $last, self::delaySeconds($last, $now));
                $bucket = [];
                $first = null;
            }
            $first ??= $at;
            $last = $at;
            $bucket[] = $destination;
        }
        if ($bucket !== [] && $last !== null) {
            $plans[] = new FollowUp($bucket, $last, self::delaySeconds($last, $now));
        }

        return $plans;
    }

    /** Whole seconds from now until `$at`, rounded up; 0 when already due. */
    public static function delaySeconds(\DateTimeImmutable $at, \DateTimeImmutable $now): int
    {
        $ms = self::ms($at) - self::ms($now);

        return $ms <= 0 ? 0 : intdiv($ms + 999, 1000);
    }

    private static function ms(\DateTimeImmutable $at): int
    {
        return (int) $at->format('U') * 1000 + (int) $at->format('v');
    }
}
