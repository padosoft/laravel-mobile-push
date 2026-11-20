<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;

/**
 * Per-process registry of connection pools, one per ConnectionProfile::$poolKey.
 *
 *  - global limit with LRU eviction: when a new key would exceed $maxPools, the least recently used idle pool
 *    is closed. Pools in use by a running send() are never evicted (the registry may exceed the limit while
 *    more than $maxPools sends are running concurrently, and shrinks back as they finish);
 *  - idle expiry: a pool unused for longer than its profile's $maxIdleSeconds is closed on the next access;
 *  - controlled close: close($key) / close(null). A pool in use is detached at once (new sends get a fresh pool)
 *    and actually closed when its last send() releases it;
 *  - fork safety: when getmypid() changes, every inherited pool is abandoned, never reused and never closed by
 *    the child (closing would make libcurl read from and write a TLS close_notify on a socket the parent still
 *    uses). See docs/transport.md for the residual risk at child shutdown.
 */
final class CurlPoolRegistry
{
    public const DEFAULT_MAX_POOLS = 32;

    private static ?self $process = null;

    /** @var array<string, CurlPool> insertion order = LRU order (oldest first) */
    private array $pools = [];

    /** @var array<string, int> pool key => max idle seconds */
    private array $maxIdle = [];

    /** @var list<CurlPool> pools detached while in use, closed on release */
    private array $closing = [];

    /** @var list<CurlPool> pools inherited across fork(): kept referenced, never touched */
    private static array $inherited = [];

    private int $pid;

    private int $evictions = 0;

    public function __construct(private readonly int $maxPools = self::DEFAULT_MAX_POOLS)
    {
        $this->pid = (int) getmypid();
    }

    /** The registry shared by every CurlTransport of this process that does not bring its own. */
    public static function process(): self
    {
        return self::$process ??= new self;
    }

    public function acquire(ConnectionProfile $profile): CurlPool
    {
        $this->guardFork();
        $this->expireIdle();

        $key = $profile->poolKey;
        $pool = $this->pools[$key] ?? null;
        if ($pool !== null) {
            unset($this->pools[$key]); // move to the most-recently-used end
            $pool->configure($profile);
        } else {
            $pool = new CurlPool($key, $profile);
        }
        $this->pools[$key] = $pool;
        $this->maxIdle[$key] = $profile->maxIdleSeconds;
        $pool->acquire();

        $this->enforceLimit();

        return $pool;
    }

    public function release(CurlPool $pool): void
    {
        $pool->release();
        if ($pool->inUse()) {
            return;
        }
        if (in_array($pool, $this->closing, true)) {
            $this->closing = array_values(array_filter($this->closing, static fn (CurlPool $p): bool => $p !== $pool));
            if ((int) getmypid() === $this->pid) {
                $pool->close();
            }

            return;
        }
        $this->enforceLimit();
    }

    public function close(?string $poolKey = null): void
    {
        $this->guardFork();
        $keys = $poolKey === null ? array_keys($this->pools) : [$poolKey];
        foreach ($keys as $key) {
            $pool = $this->pools[$key] ?? null;
            if ($pool === null) {
                continue;
            }
            $this->detach($key);
            if ($pool->inUse()) {
                $this->closing[] = $pool;
            } else {
                $pool->close();
            }
        }
    }

    /** @return list<string> pool keys currently registered, least recently used first */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->pools));
    }

    public function count(): int
    {
        return count($this->pools);
    }

    public function evictions(): int
    {
        return $this->evictions;
    }

    /** Pools inherited from a parent process and deliberately left untouched in this one. */
    public static function inheritedCount(): int
    {
        return count(self::$inherited);
    }

    public function maxPools(): int
    {
        return $this->maxPools;
    }

    private function enforceLimit(): void
    {
        if (count($this->pools) <= $this->maxPools) {
            return;
        }
        foreach ($this->pools as $key => $pool) {
            if (count($this->pools) <= $this->maxPools) {
                return;
            }
            if ($pool->inUse()) {
                continue;
            }
            $this->detach((string) $key);
            $pool->close();
            $this->evictions++;
        }
    }

    private function expireIdle(): void
    {
        $now = microtime(true);
        foreach ($this->pools as $key => $pool) {
            $key = (string) $key;
            if (! $pool->inUse() && $now - $pool->lastUsedAt() > ($this->maxIdle[$key] ?? PHP_INT_MAX)) {
                $this->detach($key);
                $pool->close();
            }
        }
    }

    private function detach(string $key): void
    {
        unset($this->pools[$key], $this->maxIdle[$key]);
    }

    private function guardFork(): void
    {
        $pid = (int) getmypid();
        if ($pid === $this->pid) {
            return;
        }
        // We are a forked child: the inherited handles share sockets with the parent.
        foreach ([...array_values($this->pools), ...$this->closing] as $pool) {
            self::$inherited[] = $pool;
        }
        $this->pools = [];
        $this->maxIdle = [];
        $this->closing = [];
        $this->pid = $pid;
    }
}
