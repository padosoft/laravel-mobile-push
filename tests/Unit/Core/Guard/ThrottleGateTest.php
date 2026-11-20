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
