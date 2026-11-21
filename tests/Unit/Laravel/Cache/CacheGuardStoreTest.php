<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
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
