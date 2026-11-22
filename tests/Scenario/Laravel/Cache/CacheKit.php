<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Cache;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\QueryException;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Laravel\Cache\CacheHealth;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Log\AbstractLogger;

/** Shared builders for the cache tests. */
final class CacheKit
{
    public static function cipher(): TokenCipher
    {
        return new TokenCipher(new KeyRing([1 => str_repeat('k', 32)]));
    }

    public static function arrayRepository(): Repository
    {
        return new Repository(new ArrayStore);
    }

    public static function health(?CollectingLogger $logger = null, ?FakeClock $clock = null): CacheHealth
    {
        return new CacheHealth($logger, $clock ?? new FakeClock);
    }

    /** Every key and value held by an ArrayStore, serialized: what a cache dump would show. */
    public static function dump(Repository $repository): string
    {
        $store = $repository->getStore();
        $reflection = new \ReflectionProperty(ArrayStore::class, 'storage');
        /** @var array<string, mixed> $storage */
        $storage = $reflection->getValue($store);

        return serialize($storage);
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function text(): string
    {
        return serialize($this->records);
    }
}

/** A cache backend that is down: every call throws, locks included. */
final class DownStore implements LockProvider, Store
{
    public int $calls = 0;

    private function down(): never
    {
        $this->calls++;

        throw new \RuntimeException('Connection refused: redis://:hunter2@cache.internal:6379');
    }

    public function get($key)
    {
        $this->down();
    }

    public function many(array $keys)
    {
        $this->down();
    }

    public function put($key, $value, $seconds)
    {
        $this->down();
    }

    public function putMany(array $values, $seconds)
    {
        $this->down();
    }

    public function increment($key, $value = 1)
    {
        $this->down();
    }

    public function decrement($key, $value = 1)
    {
        $this->down();
    }

    public function forever($key, $value)
    {
        $this->down();
    }

    public function touch($key, $seconds)
    {
        $this->down();
    }

    public function forget($key)
    {
        $this->down();
    }

    public function flush()
    {
        $this->down();
    }

    public function getPrefix()
    {
        return '';
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->down();
    }

    public function restoreLock($name, $owner): Lock
    {
        $this->down();
    }
}

/**
 * A database-backed store under contention, as MySQL answers it: taking or releasing a lock fails with a deadlock
 * (error 1213, SQLSTATE 40001) on the calls scripted in `$deadlockOnAcquire` / `$deadlockOnRelease` (1-based call
 * numbers). Everything else is an ArrayStore.
 */
final class DeadlockingStore extends ArrayStore
{
    public int $acquires = 0;

    public int $releases = 0;

    /**
     * @param  list<int>  $deadlockOnAcquire
     * @param  list<int>  $deadlockOnRelease
     */
    public function __construct(public array $deadlockOnAcquire = [], public array $deadlockOnRelease = [], public ?int $everyNthAcquire = null)
    {
        parent::__construct();
    }

    public static function deadlock(string $sql): QueryException
    {
        return new QueryException('mysql', $sql, [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            public function acquire()
            {
                assert($this->store instanceof DeadlockingStore);
                $n = ++$this->store->acquires;
                if (in_array($n, $this->store->deadlockOnAcquire, true) || ($this->store->everyNthAcquire !== null && $n % $this->store->everyNthAcquire === 0)) {
                    throw DeadlockingStore::deadlock('update `cache_locks` set `owner` = ?, `expiration` = ? where `key` = ? and `expiration` <= ?');
                }

                return parent::acquire();
            }

            public function release()
            {
                assert($this->store instanceof DeadlockingStore);
                if (in_array(++$this->store->releases, $this->store->deadlockOnRelease, true)) {
                    throw DeadlockingStore::deadlock('delete from `cache_locks` where `key` = ? and `owner` = ?');
                }

                return parent::release();
            }
        };
    }
}

/**
 * An ArrayStore that records what it is asked and can misbehave on demand: lock names and TTLs, lock attempts,
 * written TTLs, forgotten keys, raw values handed back for chosen keys, failing release / forget.
 */
final class RecordingStore extends ArrayStore
{
    /** @var list<array{string, int}> name and seconds of every lock() call */
    public array $lockCalls = [];

    /** Number of acquire() attempts made on locks of this store. */
    public int $attempts = 0;

    /** Number of release() calls made on locks of this store. */
    public int $releases = 0;

    /** The first N acquire attempts fail as if somebody else held the lock. */
    public int $heldAttempts = 0;

    public ?\Throwable $releaseError = null;

    public ?\Throwable $forgetError = null;

    /** @var list<array{string, mixed, int}> key, value and seconds of every put() */
    public array $puts = [];

    /** @var list<string> */
    public array $forgotten = [];

    /** @var array<string, mixed> raw values returned by get() instead of the stored ones */
    public array $raw = [];

    public function get($key)
    {
        return array_key_exists($key, $this->raw) ? $this->raw[$key] : parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        $this->puts[] = [$key, $value, $seconds];

        return parent::put($key, $value, $seconds);
    }

    public function forget($key)
    {
        if ($this->forgetError !== null) {
            throw $this->forgetError;
        }
        $this->forgotten[] = $key;

        return parent::forget($key);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->lockCalls[] = [$name, $seconds];

        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            public function acquire()
            {
                assert($this->store instanceof RecordingStore);
                if (++$this->store->attempts <= $this->store->heldAttempts) {
                    return false;
                }

                return parent::acquire();
            }

            public function release()
            {
                assert($this->store instanceof RecordingStore);
                $this->store->releases++;
                if ($this->store->releaseError !== null) {
                    throw $this->store->releaseError;
                }

                return parent::release();
            }
        };
    }
}

/** A working cache store that does not implement LockProvider (like the `null`-style or custom stores). */
final class NoLockStore implements Store
{
    public ArrayStore $inner;

    public function __construct()
    {
        $this->inner = new ArrayStore;
    }

    public function get($key)
    {
        return $this->inner->get($key);
    }

    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function touch($key, $seconds)
    {
        return $this->inner->touch($key, $seconds);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function getPrefix()
    {
        return '';
    }
}
