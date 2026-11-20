<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Bounded lock acquisition on a cache repository.
 *
 * Real cross-process atomicity needs a store whose locks live in shared, atomic storage: redis, memcached,
 * database, dynamodb (and file on a single host). `array` is atomic only inside one process, `null` never
 * stores anything. A store that is not a LockProvider is treated as unable to coordinate.
 *
 * @internal
 */
final class CacheLocks
{
    private const ATTEMPTS = 20;

    private const PAUSE_MICROSECONDS = 5000;

    public static function supported(Repository $cache): bool
    {
        return $cache->getStore() instanceof LockProvider;
    }

    /** Non-blocking attempt. @throws \Throwable backend errors propagate to the caller */
    public static function tryOnce(Repository $cache, string $name, int $seconds): ?Lock
    {
        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            return null;
        }
        $lock = $store->lock($name, max(1, $seconds));

        return $lock->get() ? $lock : null;
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
