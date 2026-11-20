<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function guardLimiter(FakeClock $clock, ?int $rate, float $reserve = 0.2, ?int $burst = null): RateLimiter
{
    return new RateLimiter(new InMemoryGuardStore($clock), $clock, $rate, $reserve, $burst);
}

it('grants at most the bucket and refills continuously, not on the clock minute', function () {
    $clock = new FakeClock('2026-11-20T10:00:59Z');
    $l = guardLimiter($clock, 600, 0.0);   // 10 per second

    expect($l->acquire('fcm:p', 1000, TrafficClass::Transactional))->toBe(600)
        ->and($l->acquire('fcm:p', 1, TrafficClass::Transactional))->toBe(0);

    $clock->advance(1);   // crosses a minute boundary: no reset, only 1 second of refill
    expect($l->acquire('fcm:p', 1000, TrafficClass::Transactional))->toBe(10);

    $clock->set('2026-11-20T10:01:00.500000Z');   // another 0.5 s => 5 permits
    expect($l->acquire('fcm:p', 1000, TrafficClass::Transactional))->toBe(5);
});

it('never lets bulk consume the reserved share, while transactional can', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 1000, 0.25);   // reserve 250

    expect($l->acquire('k', 5000, TrafficClass::Bulk))->toBe(750)
        ->and($l->acquire('k', 1, TrafficClass::Bulk))->toBe(0)
        ->and($l->acquire('k', 400, TrafficClass::Transactional))->toBe(250);
});

it('reports when the next permits become available', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 600, 0.0);
    $l->acquire('k', 600, TrafficClass::Bulk);

    $at = $l->availableAt('k', 30, TrafficClass::Bulk);
    expect($at->getTimestamp() - $clock->now()->getTimestamp())->toBe(3);   // 30 permits at 10/s

    $clock->advance(3);
    expect($l->acquire('k', 30, TrafficClass::Bulk))->toBe(30);
});

it('is unlimited when the rate is null and honours per account overrides', function () {
    $clock = new FakeClock;
    $unlimited = guardLimiter($clock, null);
    expect($unlimited->acquire('k', 9_999_999, TrafficClass::Bulk))->toBe(9_999_999);

    $o = new RateLimiter(new InMemoryGuardStore($clock), $clock, 10, 0.0, null, ['apns:T' => null, 'fcm:small' => 3]);
    expect($o->acquire('apns:T', 1000, TrafficClass::Bulk))->toBe(1000)
        ->and($o->acquire('fcm:small', 10, TrafficClass::Bulk))->toBe(3)
        ->and($o->acquire('fcm:other', 100, TrafficClass::Bulk))->toBe(10);
});

it('keeps accounts independent', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 100, 0.0);
    $l->acquire('fcm:a', 100, TrafficClass::Bulk);

    expect($l->acquire('fcm:b', 100, TrafficClass::Bulk))->toBe(100);
});
