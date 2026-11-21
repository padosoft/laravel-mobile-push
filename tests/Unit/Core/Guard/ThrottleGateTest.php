<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Tests\Support\FakeClock;

it('never shortens an existing pause and reopens at the instant', function () {
    $clock = new FakeClock;
    $gate = new ThrottleGate(new InMemoryGuardStore($clock), $clock);
    $long = $clock->now()->modify('+300 seconds');

    $gate->pause('fcm:p', $long);
    $effective = $gate->pause('fcm:p', $clock->now()->modify('+60 seconds'));

    expect($effective)->toEqual($long)->and($gate->pausedUntil('fcm:p'))->toEqual($long)
        ->and($gate->isOpen('fcm:p'))->toBeFalse()->and($gate->isOpen('fcm:other'))->toBeTrue();

    $clock->advance(300);
    expect($gate->isOpen('fcm:p'))->toBeTrue()->and($gate->pausedUntil('fcm:p'))->toBeNull();
});

it('extends a pause with a later one', function () {
    $clock = new FakeClock;
    $gate = new ThrottleGate(new InMemoryGuardStore($clock), $clock);
    $gate->pause('k', $clock->now()->modify('+60 seconds'));
    $later = $clock->now()->modify('+120 seconds');

    expect($gate->pause('k', $later))->toEqual($later);
});

it('judged at a given instant, reopens exactly at the end of the pause, not one millisecond before', function (string $offset, bool $open) {
    $clock = new FakeClock;
    $gate = new ThrottleGate(new InMemoryGuardStore($clock), $clock);
    $until = $clock->now()->modify('+60 seconds');
    $gate->pause('k', $until);

    expect($gate->isOpen('k', $until->modify($offset)))->toBe($open)
        ->and($gate->isOpen('k'))->toBeFalse();   // the clock itself is still inside the pause
})->with([
    'one millisecond before the end' => ['-1 millisecond', false],
    'at the end' => ['+0 seconds', true],
    'one millisecond after the end' => ['+1 millisecond', true],
]);
