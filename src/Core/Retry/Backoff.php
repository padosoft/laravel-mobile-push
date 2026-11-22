<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

use Random\Randomizer;

/**
 * Exponential backoff with jitter that never schedules earlier than the provider minimum.
 *
 * For retry n (1 = first retry): exp = min(max, base * 2^(n-1)).
 *  - exp above the provider minimum: the delay is spread DOWN from exp, by at most `jitter`, but never below
 *    the minimum: [max(min, exp * (1 - jitter)), exp].
 *  - otherwise the provider minimum dominates and the delay is spread UP from it, so that many destinations
 *    throttled at the same time do not all come back at the same instant: [min, min * (1 + jitter)].
 *
 * Bounds: minimum <= delay <= max(backoffMax, minimum * (1 + jitter)).
 */
final readonly class Backoff
{
    private Randomizer $random;

    public function __construct(private RetryPolicy $policy, ?Randomizer $random = null)
    {
        $this->random = $random ?? new Randomizer;
    }

    /**
     * @param  int  $retry  1 for the first retry, 2 for the second...
     * @param  int|null  $minDelaySeconds  provider minimum (Decision::$minDelaySeconds)
     * @return int delay in milliseconds
     */
    public function delayMs(int $retry, ?int $minDelaySeconds = null): int
    {
        $retry = max(1, $retry);
        $floor = (float) max(0, $minDelaySeconds ?? 0) * 1000.0;
        $exp = min((float) $this->policy->backoffMaxMs, $this->policy->backoffBaseMs * 2.0 ** min($retry - 1, 62));
        $spread = $this->policy->jitter * $this->unit();

        $delay = $floor >= $exp
            ? $floor * (1.0 + $spread)
            : max($floor, $exp * (1.0 - $spread));

        return (int) ceil($delay);
    }

    /**
     * A uniform float in [0, 1): 53 random bits over 2^53, every value exactly representable. Written this way
     * because Randomizer::getFloat() exists only from PHP 8.3 and the package also runs on 8.2.
     */
    private function unit(): float
    {
        return $this->random->getInt(0, (1 << 53) - 1) / (1 << 53);
    }
}
