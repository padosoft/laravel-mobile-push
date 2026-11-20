<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

/**
 * Server-side limiter of HIGH-priority update attempts per activity, over a fixed one-hour window that opens with
 * the first counted attempt and resets one hour later.
 *
 * It does not know, and never claims to know, the budget the device grants: Apple does not expose it. It only
 * stops this server from spending priority 10 beyond a configured number of attempts; beyond the limit an update
 * still goes out, at priority 5 (never deferred, so the latest state is not delayed).
 */
final readonly class HourlyLimiter
{
    public const WINDOW_SECONDS = 3600;

    /**
     * @param  int|null  $max  attempts at priority 10 allowed per window; null = no limit
     */
    public function __construct(private ?int $max) {}

    /**
     * Decide one attempt that asks for high priority.
     *
     * @param  int|null  $windowStartedAt  Unix second of the current window, null = no window yet
     * @param  int  $count  high-priority attempts already counted in that window
     */
    public function admit(?int $windowStartedAt, int $count, int $now): LimiterVerdict
    {
        if ($this->max === null) {
            return new LimiterVerdict(true, $windowStartedAt, $count);
        }

        if ($windowStartedAt === null || $now - $windowStartedAt >= self::WINDOW_SECONDS || $now < $windowStartedAt) {
            $windowStartedAt = $now;
            $count = 0;
        }

        if ($count < $this->max) {
            return new LimiterVerdict(true, $windowStartedAt, $count + 1);
        }

        return new LimiterVerdict(false, $windowStartedAt, $count);
    }
}
