<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

/** Probabilistic sampling. `$roll` is a uniform number in [0, 1): the event is kept when it is below the rate. */
final class Sampler
{
    public static function keeps(float $rate, float $roll): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        return $rate > 0.0 && $roll < $rate;
    }

    public static function roll(): float
    {
        return random_int(0, 999_999) / 1_000_000;
    }
}
