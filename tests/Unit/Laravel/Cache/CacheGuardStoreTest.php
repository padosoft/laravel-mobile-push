<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DeadlockingStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DownStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

afterEach(fn () => Carbon::setTestNow());

it('stores with a TTL and swaps only when the expected value matches', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20T10:00:00Z'));
    $store = new CacheGuardStore(CacheKit::arrayRepository(), CacheKit::health());

    expect($store->get('k'))->toBeNull()
        ->and($store->compareAndSet('k', 'stale', 'x', 30))->toBeFalse()
        ->and($store->compareAndSet('k', null, '{"a":1}', 30))->toBeTrue()
        ->and($store->compareAndSet('k', null, 'y', 30))->toBeFalse()
        ->and($store->compareAndSet('k', '{"a":1}', '{"a":2}', 30))->toBeTrue()
        ->and($store->get('k'))->toBe('{"a":2}');

    Carbon::setTestNow(Carbon::parse('2026-11-20T10:00:31Z'));
    expect($store->get('k'))->toBeNull();

    $store->put('p', 'v', 10);
    expect($store->get('p'))->toBe('v');
});

it('returns false instead of waiting when somebody else holds the lock', function () {
    $repo = CacheKit::arrayRepository();
    $store = new CacheGuardStore($repo, CacheKit::health());
    $held = $repo->getStore()->lock('mobile-push:lock:guard:'.hash('sha256', 'k'), 30);
    expect($held->get())->toBeTrue();

    expect($store->compareAndSet('k', null, 'v', 30))->toBeFalse()->and($store->get('k'))->toBeNull();
});

it('behaves as an empty store when the backend is down and never throws', function () {
    $logger = new CollectingLogger;
    $store = new CacheGuardStore(new Repository(new DownStore), CacheKit::health($logger));

    $store->put('k', 'v', 10);
    expect($store->get('k'))->toBeNull()->and($store->compareAndSet('k', null, 'v', 10))->toBeFalse()
        ->and($logger->records)->toHaveCount(1);
});

it('reports itself unavailable while the backend is down, leaves it alone for a few seconds, then tries again', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $backend = new class extends ArrayStore
    {
        public bool $down = true;

        public int $reads = 0;

        public function get($key)
        {
            $this->reads++;
            if ($this->down) {
                throw new RuntimeException('Connection refused');
            }

            return parent::get($key);
        }
    };
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', $clock);

    expect($store->available())->toBeTrue()
        ->and($store->get('k'))->toBeNull()
        ->and($store->available())->toBeFalse()
        ->and($backend->reads)->toBe(1);

    // Inside the rest window the dead backend is not contacted again.
    expect($store->get('k'))->toBeNull()
        ->and($store->compareAndSet('k', null, 'v', 10))->toBeFalse()
        ->and($backend->reads)->toBe(1);

    // After the window the backend is tried again; once it answers, the store is available and writes land.
    $backend->down = false;
    $clock->set($clock->now()->modify('+'.(CacheGuardStore::RETRY_AFTER_SECONDS + 1).' seconds'));
    expect($store->compareAndSet('k', null, 'v', 10))->toBeTrue()
        ->and($store->available())->toBeTrue()
        ->and($store->get('k'))->toBe('v');
});

it('stays available when a compare-and-set merely loses to another writer', function () {
    $store = new CacheGuardStore(CacheKit::arrayRepository(), CacheKit::health());
    $store->put('k', 'theirs', 30);

    expect($store->compareAndSet('k', 'mine', 'next', 30))->toBeFalse()
        ->and($store->available())->toBeTrue();
});

/*
 * Found on MySQL 8.4 with the `database` cache store and eight processes (CacheCoordinationTest): the store's lock
 * table answers a contended lock with a deadlock. Treated as an outage, it made the guards of that process fail open
 * for five seconds: 175 permits granted from a budget of 100.
 */
it('a deadlock answered while taking the lock is a lost compare-and-set, not an outage', function () {
    $logger = new CollectingLogger;
    $backend = new DeadlockingStore(deadlockOnAcquire: [1]);
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health($logger), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));

    // The deadlocked attempt counts as "lock not taken": the bounded wait tries again and gets it.
    expect($store->compareAndSet('k', null, 'v', 30))->toBeTrue()
        ->and($backend->acquires)->toBe(2)
        ->and($store->available())->toBeTrue()
        ->and($store->get('k'))->toBe('v')
        ->and($logger->records)->toBe([]);
});

it('a lock that deadlocks on every attempt costs one lost compare-and-set and leaves the store available', function () {
    $backend = new DeadlockingStore(everyNthAcquire: 1);
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));

    expect($store->compareAndSet('k', null, 'v', 30))->toBeFalse()
        ->and($store->available())->toBeTrue()
        ->and($store->get('k'))->toBeNull();
});

it('a deadlock while releasing is retried, so the lock is not left behind for the next writer', function () {
    $backend = new DeadlockingStore(deadlockOnRelease: [1, 2]);
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));

    expect($store->compareAndSet('k', null, 'a', 30))->toBeTrue()
        ->and($backend->releases)->toBe(3)
        // Had the lock stayed, this would wait its 100 ms and lose.
        ->and($store->compareAndSet('k', 'a', 'b', 30))->toBeTrue()
        ->and($store->available())->toBeTrue();
});

it('the rate limiter never grants above its budget while the lock table keeps deadlocking', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');   // frozen: nothing refills
    $backend = new DeadlockingStore(everyNthAcquire: 3);
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', $clock);
    $limiter = new RateLimiter($store, $clock, 10, 0.0, 10);

    $granted = 0;
    for ($i = 0; $i < 30; $i++) {
        $granted += $limiter->acquire('apns|team:TEAM123456', 1, TrafficClass::Transactional);
    }

    expect($granted)->toBe(10)
        ->and($backend->acquires)->toBeGreaterThan(10)   // ten swaps, plus the attempts lost to a deadlock
        ->and($store->available())->toBeTrue();
});

it('an error that is not contention still marks the store unavailable', function () {
    $backend = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        }
    };
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));

    expect($store->compareAndSet('k', null, 'v', 30))->toBeFalse()
        ->and($store->available())->toBeFalse();
});
