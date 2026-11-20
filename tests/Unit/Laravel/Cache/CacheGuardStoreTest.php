<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DownStore;

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
