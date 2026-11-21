<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeStoreUnavailable;
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

it('reports a dead store on writes and reads as empty', function () {
    $counter = new BadgeCounter(new SilentDeadGuardStore, new FakeClock, maxAttempts: 2);

    expect($counter->current('s', 'u'))->toBe(0);
    $counter->increment('s', 'u', 'a');
})->throws(BadgeStoreUnavailable::class);
