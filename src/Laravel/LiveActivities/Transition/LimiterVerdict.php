<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

final readonly class LimiterVerdict
{
    public function __construct(
        /** True: send at priority 10 (and the attempt was counted). False: send at priority 5. */
        public bool $high,
        public ?int $windowStartedAt,
        public int $count,
    ) {}
}
