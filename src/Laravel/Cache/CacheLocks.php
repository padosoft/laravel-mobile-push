<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\DetectsConcurrencyErrors;

/**
 * Bounded lock acquisition on a cache repository.
 *
 * Real cross-process atomicity needs a store whose locks live in shared, atomic storage: redis, memcached,
 * database, dynamodb (and file on a single host). `array` is atomic only inside one process, `null` never
 * stores anything. A store that is not a LockProvider is treated as unable to coordinate.
 *
 * Contention is not an outage. The `database` store takes a lock with an INSERT and, when the row exists, an UPDATE
 * of the expired one: with several processes after the same lock MySQL answers some of them with a deadlock (error
 * 1213, SQLSTATE 40001) instead of making them wait. That answer means "you did not get the lock", exactly like a
 * lock held by somebody else, so it is reported as such and never as an unreachable backend (which would make the
 * guards fail open).
 *
 * @internal
 */
final class CacheLocks
{
    private const ATTEMPTS = 20;

    use DetectsConcurrencyErrors;

    private const PAUSE_MICROSECONDS = 5000;

    private const RELEASE_ATTEMPTS = 3;

    /** A deadlock, a serialization failure or a lock wait timeout reported by a database-backed store. */
    public static function contention(\Throwable $error): bool
    {
        return (new self)->causedByConcurrencyError($error);
    }

    public static function supported(Repository $cache): bool
    {
        return $cache->getStore() instanceof LockProvider;
    }

    /**
     * Non-blocking attempt; null when the lock is held or lost to contention.
     *
     * @throws \Throwable backend errors propagate to the caller
     */
    public static function tryOnce(Repository $cache, string $name, int $seconds): ?Lock
    {
        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            return null;
        }
        $lock = $store->lock($name, max(1, $seconds));

        try {
            return $lock->get() ? $lock : null;
        } catch (\Throwable $e) {
            if (self::contention($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Release a lock. Losing a deadlock while deleting the lock row is retried: a lock left behind would keep every
     * other process out until it expires.
     *
     * @throws \Throwable backend errors propagate to the caller
     */
    public static function release(Lock $lock): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $lock->release();

                return;
            } catch (\Throwable $e) {
                if ($attempt >= self::RELEASE_ATTEMPTS || ! self::contention($e)) {
                    throw $e;
                }
                usleep(self::PAUSE_MICROSECONDS);
            }
        }
    }

    /**
     * Waits at most ~100 ms (20 attempts, 5 ms apart); null on timeout.
     *
     * @throws \Throwable backend errors propagate to the caller
     */
    public static function acquire(Repository $cache, string $name, int $seconds): ?Lock
    {
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $lock = self::tryOnce($cache, $name, $seconds);
            if ($lock !== null) {
                return $lock;
            }
            if (! self::supported($cache)) {
                return null;
            }
            usleep(self::PAUSE_MICROSECONDS);
        }

        return null;
    }
}
