<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

/** One rolling window of a limit: at most `$limit` effects in the last `$seconds` seconds. */
final readonly class FrequencyWindow
{
    public function __construct(
        public string $name,
        public int $seconds,
        public int $limit,
    ) {
        if ($seconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('A frequency window needs positive seconds and limit.');
        }
    }

    /** A moment `$at` (Unix seconds) is inside the window ending at `$now` when it is strictly newer than now - seconds. */
    public function contains(int $at, int $now): bool
    {
        return $at > $now - $this->seconds;
    }

    public function describe(): string
    {
        return $this->limit.' per '.$this->name;
    }
}
