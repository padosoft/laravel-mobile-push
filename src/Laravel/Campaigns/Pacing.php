<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/**
 * Per-campaign pacing of the PLANNER (how fast deliveries are written), on top of the shared rate limiter that
 * paces the network. Integer milliseconds only.
 *
 * With `perMinute` = N, a slice holds at most N destinations and the next slice may start
 * ceil(written * 60000 / N) ms after the previous one was committed. Time a slow slice spent is never caught up
 * with a burst: the next instant is always counted from the commit.
 */
final class Pacing
{
    public const MAX_RATE = 10_000_000;

    public static function assertRate(?int $perMinute): void
    {
        if ($perMinute !== null && ($perMinute < 1 || $perMinute > self::MAX_RATE)) {
            throw new \InvalidArgumentException('A campaign rate must be between 1 and '.self::MAX_RATE.' per minute.');
        }
    }

    /** The destinations to read in one slice. */
    public static function sliceSize(int $slice, ?int $perMinute): int
    {
        $slice = max(1, $slice);

        return $perMinute === null ? $slice : max(1, min($slice, $perMinute));
    }

    /** The earliest start of the next slice (epoch ms), or null when the campaign is not paced. */
    public static function nextSliceAt(int $committedAtMs, int $written, ?int $perMinute): ?int
    {
        if ($perMinute === null) {
            return null;
        }

        return $committedAtMs + intdiv(max(0, $written) * 60_000 + $perMinute - 1, $perMinute);
    }

    /** Whole seconds to wait from `$nowMs` until `$atMs` (rounded up, never negative). */
    public static function delaySeconds(?int $atMs, int $nowMs): int
    {
        if ($atMs === null || $atMs <= $nowMs) {
            return 0;
        }

        return intdiv($atMs - $nowMs + 999, 1000);
    }
}
