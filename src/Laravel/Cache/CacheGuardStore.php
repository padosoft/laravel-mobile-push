<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Cache;

use Illuminate\Cache\Repository;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\ReportsAvailability;
use Psr\Clock\ClockInterface;

/**
 * GuardStore on a Laravel cache repository. compareAndSet runs read-compare-write under a cache lock; when the lock
 * cannot be had within ~100 ms it returns false so the caller's CAS loop retries. An unavailable backend behaves as an
 * empty store (get null, put no-op, compareAndSet false) and never throws; available() then answers false, which
 * makes the guards fail open (see StateCell), and the backend is left alone for RETRY_AFTER_SECONDS so that a dead
 * cache server does not cost a connection timeout on every send.
 *
 * Guard state holds counters and timestamps only, no secrets, so values are stored in clear. Real cross-process
 * atomicity needs redis, memcached, database or dynamodb (file: one host; array: one process; null: nothing).
 */
final class CacheGuardStore implements GuardStore, ReportsAvailability
{
    public const RETRY_AFTER_SECONDS = 5;

    private const CAS_LOCK_SECONDS = 5;

    private bool $available = true;

    /** Unix seconds until which the backend is not contacted after a failure (needs a clock). */
    private int $restUntil = 0;

    public function __construct(
        private readonly Repository $cache,
        private readonly CacheHealth $health,
        private readonly string $prefix = 'mobile-push',
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function get(string $key): ?string
    {
        if ($this->resting()) {
            return null;
        }

        try {
            $value = $this->read($key);
            $this->available = true;

            return $value;
        } catch (\Throwable $e) {
            $this->down('guard.get', $e);

            return null;
        }
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        if ($this->resting()) {
            return;
        }

        try {
            $this->cache->put($this->entryKey($key), $value, max(1, $ttlSeconds));
            $this->available = true;
        } catch (\Throwable $e) {
            $this->down('guard.put', $e);
        }
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        if ($this->resting()) {
            return false;
        }
        $lock = null;

        try {
            if (! CacheLocks::supported($this->cache)) {
                $this->health->unsupported('guard.compareAndSet', 'cache store without lock support');
                $this->available = false;

                return false;
            }

            $lock = CacheLocks::acquire($this->cache, $this->prefix.':lock:guard:'.hash('sha256', $key), self::CAS_LOCK_SECONDS);
            if ($lock === null) {
                return false;
            }

            $current = $this->read($key);
            $this->available = true;
            if ($current !== $expected) {
                return false;
            }

            $this->cache->put($this->entryKey($key), $value, max(1, $ttlSeconds));

            return true;
        } catch (\Throwable $e) {
            $this->down('guard.compareAndSet', $e);

            return false;
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable $e) {
                $this->health->failed('guard.release', $e);
            }
        }
    }

    private function resting(): bool
    {
        return ! $this->available && $this->clock !== null && $this->clock->now()->getTimestamp() < $this->restUntil;
    }

    private function down(string $operation, \Throwable $error): void
    {
        $this->available = false;
        $this->restUntil = ($this->clock?->now()->getTimestamp() ?? 0) + self::RETRY_AFTER_SECONDS;
        $this->health->failed($operation, $error);
    }

    /** @throws \Throwable */
    private function read(string $key): ?string
    {
        $raw = $this->cache->get($this->entryKey($key));

        // Some drivers (redis) hand numeric strings back as numbers.
        return is_string($raw) ? $raw : (is_int($raw) || is_float($raw) ? (string) $raw : null);
    }

    private function entryKey(string $key): string
    {
        return $this->prefix.':guard:'.hash('sha256', $key);
    }
}
