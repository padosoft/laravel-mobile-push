<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

/** The limits of one policy bucket: no window at all means unlimited. Windows are ordered shortest first. */
final readonly class BucketLimits
{
    public const HOUR = 3600;

    public const DAY = 86400;

    /** @param  list<FrequencyWindow>  $windows */
    private function __construct(public array $windows) {}

    public static function unlimited(): self
    {
        return new self([]);
    }

    public static function of(?int $perHour = null, ?int $perDay = null): self
    {
        $windows = [];
        if ($perHour !== null) {
            $windows[] = new FrequencyWindow('hour', self::HOUR, $perHour);
        }
        if ($perDay !== null) {
            $windows[] = new FrequencyWindow('day', self::DAY, $perDay);
        }

        return new self($windows);
    }

    public function isUnlimited(): bool
    {
        return $this->windows === [];
    }

    public function longestWindowSeconds(): int
    {
        $longest = 0;
        foreach ($this->windows as $window) {
            $longest = max($longest, $window->seconds);
        }

        return $longest;
    }

    /** How many effects can be in the longest window at most. */
    public function largestLimit(): int
    {
        $largest = 0;
        foreach ($this->windows as $window) {
            $largest = max($largest, $window->limit);
        }

        return $largest;
    }

    public function windowNamed(string $name): ?FrequencyWindow
    {
        foreach ($this->windows as $window) {
            if ($window->name === $name) {
                return $window;
            }
        }

        return null;
    }
}
