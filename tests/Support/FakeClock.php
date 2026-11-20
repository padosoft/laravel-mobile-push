<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * Deterministic clock for tests. Shared by every test suite: do not create another one.
 */
final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = '2026-11-20T10:00:00Z')
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): self
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));

        return $this;
    }

    public function set(\DateTimeImmutable|string $now): self
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now) : $now;

        return $this;
    }
}
