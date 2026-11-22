<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Padosoft\MobilePush\Laravel\Cache\CacheLocks;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DeadlockingStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\NoLockStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\RecordingStore;

// The doubles share the file of CacheKit: loading it makes them available.
class_exists(CacheKit::class);

it('knows which stores can lock, and a store that cannot never hands out a lock', function () {
    $plain = new Repository(new NoLockStore);

    expect(CacheLocks::supported(new Repository(new ArrayStore)))->toBeTrue()
        ->and(CacheLocks::supported($plain))->toBeFalse()
        ->and(CacheLocks::tryOnce($plain, 'n', 5))->toBeNull()
        ->and(CacheLocks::acquire($plain, 'n', 5))->toBeNull();
});

it('asks the store for at least one second of lock, whatever the caller passes', function (int $asked, int $expected) {
    $store = new RecordingStore;

    expect(CacheLocks::tryOnce(new Repository($store), 'name', $asked))->not->toBeNull()
        ->and($store->lockCalls)->toBe([['name', $expected]]);
})->with([[0, 1], [-7, 1], [1, 1], [2, 2], [30, 30]]);

it('tryOnce returns a held lock only once, and the lock can be released and taken again', function () {
    $repo = new Repository(new ArrayStore);

    $first = CacheLocks::tryOnce($repo, 'n', 30);
    expect($first)->not->toBeNull()
        ->and(CacheLocks::tryOnce($repo, 'n', 30))->toBeNull();

    CacheLocks::release($first);
    expect(CacheLocks::tryOnce($repo, 'n', 30))->not->toBeNull();
});

it('a deadlock while taking the lock is "not taken"; any other backend error propagates', function () {
    expect(CacheLocks::tryOnce(new Repository(new DeadlockingStore(deadlockOnAcquire: [1])), 'n', 5))->toBeNull();

    $broken = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            throw new RuntimeException('Connection refused');
        }
    };
    expect(fn () => CacheLocks::tryOnce(new Repository($broken), 'n', 5))->toThrow(RuntimeException::class, 'Connection refused');
});

it('acquire stops at the first success', function () {
    $store = new RecordingStore;
    $store->heldAttempts = 3;

    expect(CacheLocks::acquire(new Repository($store), 'n', 5))->not->toBeNull()
        ->and($store->attempts)->toBe(4)
        ->and($store->lockCalls)->toHaveCount(4);
});

it('acquire gives up after exactly 20 attempts while somebody else holds the lock', function () {
    $store = new RecordingStore;
    $store->heldAttempts = PHP_INT_MAX;

    expect(CacheLocks::acquire(new Repository($store), 'n', 5))->toBeNull()
        ->and($store->attempts)->toBe(20);
});

it('acquire succeeds on the last allowed attempt but not on the one after', function (int $heldAttempts, bool $gotIt) {
    $store = new RecordingStore;
    $store->heldAttempts = $heldAttempts;

    expect(CacheLocks::acquire(new Repository($store), 'n', 5) !== null)->toBe($gotIt);
})->with([[19, true], [20, false]]);

it('release retries a deadlock up to three attempts in total, then rethrows it', function (array $deadlocks, int $releases, bool $throws) {
    $store = new DeadlockingStore(deadlockOnRelease: $deadlocks);
    $lock = CacheLocks::tryOnce(new Repository($store), 'n', 5);
    expect($lock)->not->toBeNull();

    if ($throws) {
        expect(fn () => CacheLocks::release($lock))->toThrow(QueryException::class);
    } else {
        CacheLocks::release($lock);
    }

    expect($store->releases)->toBe($releases);
})->with([
    'clean' => [[], 1, false],
    'one deadlock' => [[1], 2, false],
    'two deadlocks' => [[1, 2], 3, false],
    'three deadlocks' => [[1, 2, 3], 3, true],
]);

it('release does not retry an error that is not contention', function () {
    $store = new RecordingStore;
    $store->releaseError = new RuntimeException('Connection refused');
    $lock = CacheLocks::tryOnce(new Repository($store), 'n', 5);

    expect(fn () => CacheLocks::release($lock))->toThrow(RuntimeException::class, 'Connection refused')
        ->and($store->releases)->toBe(1);
});

it('tells contention from an unreachable backend', function (Closure $error, bool $contention) {
    expect(CacheLocks::contention($error()))->toBe($contention);
})->with([
    'deadlock' => [fn () => DeadlockingStore::deadlock('delete from `cache_locks`'), true],
    'connection refused' => [fn () => new RuntimeException('Connection refused'), false],
    'plain error' => [fn () => new LogicException('boom'), false],
]);
