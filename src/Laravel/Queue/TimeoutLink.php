<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** One "lower < upper, with margin" requirement of the timeout chain. */
final readonly class TimeoutLink
{
    public function __construct(
        public string $lower,
        public string $upper,
        /** Seconds; null when unknown. */
        public ?float $lowerSeconds,
        public ?float $upperSeconds,
        /** Minimum headroom required between the two, in seconds. */
        public float $marginSeconds,
        public ChainStatus $status,
        /** What to change, for a violation or an unverifiable link. */
        public ?string $remedy = null,
    ) {}

    public function describe(): string
    {
        $fmt = static fn (?float $s): string => $s === null ? '?' : rtrim(rtrim(number_format($s, 3, '.', ''), '0'), '.').' s';

        return sprintf('%s (%s) < %s (%s), margin %s: %s', $this->lower, $fmt($this->lowerSeconds), $this->upper, $fmt($this->upperSeconds),
            $fmt($this->marginSeconds), $this->status->value).($this->remedy === null ? '' : ' - '.$this->remedy);
    }
}
