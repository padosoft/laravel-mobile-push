<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4).'/Scenario/Policy/Frequency/PolicyKit.php';

use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeStoreUnavailable;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\BusyGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\SilentDeadGuardStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function badge(?FakeClock &$clock = null, int $markerTtl = 3600): BadgeCounter
{
    $clock = new FakeClock('2026-11-20T10:00:00Z');

    return new BadgeCounter(new InMemoryGuardStore($clock), $clock, 86400, $markerTtl);
}

it('increments once per effect and remembers the number', function () {
    $counter = badge();

    expect([$counter->increment('s', 'u', 'a'), $counter->increment('s', 'u', 'b'), $counter->increment('s', 'u', 'a'), $counter->increment('s', 'u', null), $counter->increment('s', 'u', null)])
        ->toBe([1, 2, 1, 3, 4]);
});

it('forgets an idempotency marker after its TTL', function () {
    $counter = badge($clock, markerTtl: 3600);
    $counter->increment('s', 'u', 'a');
    $clock->advance(3601);

    expect($counter->increment('s', 'u', 'a'))->toBe(2);
});

it('keeps markers across set and reset, and never goes negative', function () {
    $counter = badge();
    $counter->increment('s', 'u', 'a');
    $counter->set('s', 'u', 10);
    expect($counter->current('s', 'u'))->toBe(10)
        ->and($counter->increment('s', 'u', 'a'))->toBe(1) // the retry reports what it was told, never counts again
        ->and($counter->current('s', 'u'))->toBe(10);
    $counter->set('s', 'u', -4);
    expect($counter->current('s', 'u'))->toBe(0);
    $counter->reset('s', 'u');
    expect($counter->current('s', 'u'))->toBe(0);
});

it('lets the counter expire after its TTL of inactivity', function () {
    $counter = badge($clock);
    $counter->increment('s', 'u', 'a');
    $clock->advance(86401);

    expect($counter->current('s', 'u'))->toBe(0);
});

it('reports a dead store on the first lost write and reads as empty', function () {
    $store = new SilentDeadGuardStore;
    $counter = new BadgeCounter($store, new FakeClock, maxAttempts: 4);

    expect($counter->current('s', 'u'))->toBe(0)
        ->and(fn () => $counter->increment('s', 'u', 'a'))->toThrow(BadgeStoreUnavailable::class, 'unavailable')
        ->and($store->compareAndSets)->toBe(1);
});

it('gives up on a live store whose writes keep losing only after the bounded attempts, and counts nothing', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $inner = new InMemoryGuardStore($clock);
    $store = new BusyGuardStore($inner);
    $counter = new BadgeCounter($store, $clock, maxAttempts: 4);

    expect(fn () => $counter->increment('s', 'u', 'a'))->toThrow(BadgeStoreUnavailable::class, 'too contended')
        ->and($store->compareAndSets)->toBe(4)
        ->and((new BadgeCounter($inner, $clock))->current('s', 'u'))->toBe(0);
});

it('counts once when the write is lost a few times and then lands', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $inner = new InMemoryGuardStore($clock);
    $store = new BusyGuardStore($inner, losses: 5);
    $counter = new BadgeCounter($store, $clock);

    expect($counter->increment('s', 'u', 'a'))->toBe(1)
        ->and($store->compareAndSets)->toBe(6)
        ->and($counter->current('s', 'u'))->toBe(1);
});
