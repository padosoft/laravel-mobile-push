<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Auth;

use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Psr\Clock\ClockInterface;

/**
 * A real InMemoryTokenStore seen through a window: it records what a provider asks of the shared store
 * (reads, compare-and-swaps, lock requests and releases) and lets a test play "another worker"
 * (deny the lock, write right after the lock is granted, keep entries longer than asked).
 */
final class RecordingTokenStore implements TokenStore
{
    public readonly InMemoryTokenStore $inner;

    public int $gets = 0;

    /** @var list<array{key: string, expected: ?string, ttl: int}> */
    public array $swaps = [];

    /** @var list<array{key: string, seconds: int}> */
    public array $lockRequests = [];

    public int $releases = 0;

    /** When true, another worker holds the refresh lock forever. */
    public bool $denyLocks = false;

    /** @var (\Closure(string): void)|null runs right after a lock is granted (a concurrent writer finishing first) */
    public ?\Closure $afterLock = null;

    /** Seconds added to every TTL: models a backend whose entries outlive the token (e.g. a coarse TTL). */
    public int $extraTtl = 0;

    public function __construct(ClockInterface $clock)
    {
        $this->inner = new InMemoryTokenStore($clock);
    }

    public function get(string $cacheKey): ?AuthToken
    {
        $this->gets++;

        return $this->inner->get($cacheKey);
    }

    public function compareAndSwap(string $cacheKey, ?string $expectedGeneration, AuthToken $new, int $ttlSeconds): AuthToken
    {
        $this->swaps[] = ['key' => $cacheKey, 'expected' => $expectedGeneration, 'ttl' => $ttlSeconds];

        return $this->inner->compareAndSwap($cacheKey, $expectedGeneration, $new, $ttlSeconds + $this->extraTtl);
    }

    public function acquireRefreshLock(string $cacheKey, int $seconds): bool
    {
        $this->lockRequests[] = ['key' => $cacheKey, 'seconds' => $seconds];
        if ($this->denyLocks) {
            return false;
        }
        $granted = $this->inner->acquireRefreshLock($cacheKey, $seconds);
        if ($granted && $this->afterLock !== null) {
            ($this->afterLock)($cacheKey);
        }

        return $granted;
    }

    public function releaseRefreshLock(string $cacheKey): void
    {
        $this->releases++;
        $this->inner->releaseRefreshLock($cacheKey);
    }

    public function forget(string $cacheKey): void
    {
        $this->inner->forget($cacheKey);
    }

    /** Overwrite whatever is stored, as another process would. */
    public function install(string $cacheKey, AuthToken $token, int $ttlSeconds = 3600): void
    {
        $this->inner->forget($cacheKey);
        $this->inner->compareAndSwap($cacheKey, null, $token, $ttlSeconds);
    }
}
