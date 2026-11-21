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

it('grants nothing and consumes nothing for an empty or negative request', function (?int $rate, int $n) {
    $clock = new FakeClock;
    $l = guardLimiter($clock, $rate, 0.0);

    expect($l->acquire('k', $n, TrafficClass::Transactional))->toBe(0);
    if ($rate !== null) {
        expect($l->acquire('k', $rate + 1, TrafficClass::Transactional))->toBe($rate);
    }
})->with([
    'zero, limited' => [100, 0],
    'negative, limited' => [100, -5],
    'negative, unlimited' => [null, -5],
]);

it('refills exactly the rate per minute', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 60_000, 0.0, 120_000);

    expect($l->acquire('k', 200_000, TrafficClass::Transactional))->toBe(120_000);
    $clock->advance(60);
    expect($l->acquire('k', 200_000, TrafficClass::Transactional))->toBe(60_000);
});

it('holds at least one permit whatever the burst', function (int $burst) {
    $l = guardLimiter(new FakeClock, 100, 0.0, $burst);

    expect($l->acquire('k', 5, TrafficClass::Transactional))->toBe(1);
})->with(['burst 0' => 0, 'burst 1' => 1]);

it('rounds the reserved share down', function () {
    $l = guardLimiter(new FakeClock, 10, 0.25);   // 2.5 reserved -> 2

    expect($l->acquire('k', 100, TrafficClass::Bulk))->toBe(8);
});

it('gives bulk nothing (never a negative grant) once transactional traffic dipped into the reserve', function () {
    $l = guardLimiter(new FakeClock, 1000, 0.25);
    $l->acquire('k', 900, TrafficClass::Transactional);   // 100 left, reserve 250

    expect($l->acquire('k', 100, TrafficClass::Bulk))->toBe(0)
        ->and($l->acquire('k', 1000, TrafficClass::Transactional))->toBe(100);
});

it('a clock that steps back neither loses nor mints permits', function () {
    $clock = new FakeClock('2026-11-20T10:00:10Z');
    $l = guardLimiter($clock, 600, 0.0);   // 10 per second
    expect($l->acquire('k', 1, TrafficClass::Transactional))->toBe(1);

    $clock->set('2026-11-20T10:00:05Z');   // this worker's clock is 5 s behind
    expect($l->acquire('k', 1000, TrafficClass::Transactional))->toBe(599);

    $clock->set('2026-11-20T10:00:10Z');   // back where the bucket was last touched: no refill yet
    expect($l->acquire('k', 1000, TrafficClass::Transactional))->toBe(0);
});

it('the stored bucket outlives its refill time, so an expiry never hands out a full bucket early', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 1, 0.0, 120);   // 120 permits take 7200 s to refill

    expect($l->acquire('k', 120, TrafficClass::Transactional))->toBe(120);
    $clock->advance(7140);
    expect($l->acquire('k', 120, TrafficClass::Transactional))->toBe(119);
});

it('availableAt is now when nothing has to be waited for', function (?int $rate, bool $drained, int $n, TrafficClass $class) {
    $clock = new FakeClock('2026-11-20T10:00:00.000400Z');   // sub-millisecond: the instant is returned unchanged
    $l = guardLimiter($clock, $rate, 0.25);
    if ($drained) {
        $l->acquire('k', 1_000_000, TrafficClass::Transactional);
    }

    expect($l->availableAt('k', $n, $class))->toEqual($clock->now());
})->with([
    'unlimited' => [null, false, 5, TrafficClass::Bulk],
    'zero permits on a drained bucket' => [1000, true, 0, TrafficClass::Bulk],
    'a full bucket asked for all of it' => [1000, false, 1000, TrafficClass::Transactional],
    'a full bucket asked for its usable part' => [1000, false, 750, TrafficClass::Bulk],
]);

it('availableAt names the millisecond the permit is due: one millisecond before, at, and after the boundary', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 1, 0.0);   // one permit per minute, bucket of one
    $t0 = $clock->now();
    $l->acquire('k', 1, TrafficClass::Transactional);

    $clock->set($t0->modify('+59999 milliseconds'));
    expect($l->availableAt('k', 1, TrafficClass::Transactional))->toEqual($t0->modify('+60 seconds'))
        ->and($l->acquire('k', 1, TrafficClass::Transactional))->toBe(0);

    $clock->set($t0->modify('+60 seconds'));
    expect($l->availableAt('k', 1, TrafficClass::Transactional))->toEqual($clock->now())
        ->and($l->acquire('k', 1, TrafficClass::Transactional))->toBe(1);
});

it('availableAt rounds up to whole milliseconds without adding one', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 600, 0.0);   // 0.01 permit per millisecond
    $t0 = $clock->now();
    $l->acquire('k', 600, TrafficClass::Transactional);

    expect($l->availableAt('k', 30, TrafficClass::Transactional))->toEqual($t0->modify('+3000 milliseconds'));
});

it('availableAt for bulk waits for the permits above the reserve, counting what has already refilled', function (int $asked, int $dueAfterMs) {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 1000, 0.25);   // reserve 250, usable by bulk 750
    $t0 = $clock->now();
    $l->acquire('k', 1000, TrafficClass::Transactional);
    $clock->advance(1);                      // 16.67 permits back

    expect($l->availableAt('k', $asked, TrafficClass::Bulk))->toEqual($t0->modify("+{$dueAfterMs} milliseconds"));
})->with([
    '30 permits: 280 in the bucket' => [30, 16_800],
    'more than the usable capacity: a full bucket' => [5000, 60_000],
]);

it('availableAt never waits for more permits than the bucket holds', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 60, 0.0, 1);   // one per second, bucket of one
    $t0 = $clock->now();
    $l->acquire('k', 1, TrafficClass::Transactional);

    expect($l->availableAt('k', 5, TrafficClass::Transactional))->toEqual($t0->modify('+1 second'));
});

it('with the whole capacity reserved, bulk gets nothing and is never told to retry now', function () {
    $clock = new FakeClock;
    $l = guardLimiter($clock, 1000, 1.0);

    expect($l->acquire('k', 1, TrafficClass::Bulk))->toBe(0)
        ->and($l->availableAt('k', 1, TrafficClass::Bulk))->toBeGreaterThan($clock->now());
});
