<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Psr\Clock\ClockInterface;

/** Process-local store. Two limiter instances sharing one instance behave like two workers sharing a cache. */
final class InMemoryGuardStore implements GuardStore
{
    /** @var array<string, array{0: string, 1: int}> value and expiry (Unix milliseconds) */
    private array $data = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function get(string $key): ?string
    {
        $entry = $this->data[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry[1] <= Millis::of($this->clock->now())) {
            unset($this->data[$key]);

            return null;
        }

        return $entry[0];
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->data[$key] = [$value, Millis::of($this->clock->now()) + max(1, $ttlSeconds) * 1000];
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        if ($this->get($key) !== $expected) {
            return false;
        }
        $this->put($key, $value, $ttlSeconds);

        return true;
    }
}
