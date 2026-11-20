<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\AccountKey;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Tests\Support\FakeClock;

it('two workers and two aliases on the same Firebase project never exceed the per-minute rate', function () {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);   // the shared cache
    $rate = 6000;
    $burst = 100;
    // Two workers, each with its own limiter instance; both aliases resolve to the same project key.
    $workerA = new RateLimiter($store, $clock, $rate, 0.0, $burst);
    $workerB = new RateLimiter($store, $clock, $rate, 0.0, $burst);
    $key = AccountKey::of(Provider::Fcm, 'shop-prod');

    $perSecond = [];
    $total = 0;
    for ($s = 0; $s < 600; $s++) {   // 10 simulated minutes, both workers ask for far more than allowed
        $granted = 0;
        foreach ([[$workerA, 700], [$workerB, 900], [$workerA, 300], [$workerB, 500]] as [$w, $n]) {
            $granted += $w->acquire($key, $n, TrafficClass::Bulk);
        }
        $perSecond[] = $granted;
        $total += $granted;
        $clock->advance(1);
    }

    expect($total)->toBeLessThanOrEqual($rate * 10 + $burst)
        ->and($total)->toBeGreaterThanOrEqual($rate * 10);   // and the limit is actually used

    for ($i = 0; $i + 60 <= 600; $i++) {   // every rolling minute stays within rate + burst
        expect(array_sum(array_slice($perSecond, $i, 60)))->toBeLessThanOrEqual($rate + $burst);
    }
});

it('a campaign cannot starve transactional pushes', function () {
    $clock = new FakeClock;
    $limiter = new RateLimiter(new InMemoryGuardStore($clock), $clock, 1000, 0.2);
    $key = AccountKey::of(Provider::Fcm, 'shop-prod');

    $bulk = 0;
    foreach (range(1, 50) as $_) {          // the campaign hammers the limiter in 100-message jobs
        $bulk += $limiter->acquire($key, 100, TrafficClass::Bulk);
    }
    expect($bulk)->toBe(800);                // bulk stops 200 short of the capacity

    $transactional = 0;
    foreach (range(1, 30) as $_) {
        $transactional += $limiter->acquire($key, 10, TrafficClass::Transactional);
    }
    expect($transactional)->toBe(200);       // the whole reserved share is still there

    $clock->advance(6);                      // 100 permits refill; bulk may use them again above the reserve
    expect($limiter->acquire($key, 1000, TrafficClass::Transactional))->toBe(100);
});
