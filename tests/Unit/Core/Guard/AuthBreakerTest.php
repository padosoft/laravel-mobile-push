<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\BreakerTransition as T;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function authBreaker(FakeClock $clock, int $threshold = 3, int $cooldown = 60): AuthBreaker
{
    return new AuthBreaker(new InMemoryGuardStore($clock), $clock, $threshold, $cooldown);
}

it('walks closed, open, half-open, closed with exact transitions', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock);

    expect($b->recordFailure('c', 0))->toBe(T::None)
        ->and($b->recordFailure('c', 0))->toBe(T::None)
        ->and($b->recordFailure('c', 0))->toBe(T::Opened)
        ->and($b->check('c')->allowed)->toBeFalse();

    $clock->advance(59);
    expect($b->check('c')->allowed)->toBeFalse();

    $clock->advance(1);
    $probe = $b->check('c');
    expect($probe->allowed)->toBeTrue()->and($probe->probe)->toBeTrue()->and($probe->transition)->toBe(T::HalfOpened)
        ->and($b->check('c')->allowed)->toBeFalse();   // only one probe

    expect($b->recordSuccess('c', 0))->toBe(T::Closed)
        ->and($b->check('c')->allowed)->toBeTrue();
});

it('reopens when the probe fails and restarts the cooldown', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock, 1);
    $b->recordFailure('c', 0);
    $clock->advance(60);
    $b->check('c');

    expect($b->recordFailure('c', 0))->toBe(T::Reopened)
        ->and($b->check('c')->allowed)->toBeFalse();
    $clock->advance(60);
    expect($b->check('c')->probe)->toBeTrue();
});

it('counts consecutive failures only', function () {
    $b = authBreaker(new FakeClock);
    $b->recordFailure('c', 0);
    $b->recordFailure('c', 0);
    $b->recordSuccess('c', 0);

    expect($b->recordFailure('c', 0))->toBe(T::None)->and($b->isOpen('c'))->toBeFalse();
});

it('fails over once to the secondary and then ignores stale failures', function () {
    $b = authBreaker(new FakeClock, 2);
    $b->recordFailure('c', 0, true);

    expect($b->recordFailure('c', 0, true))->toBe(T::FailedOver)
        ->and($b->activeGeneration('c'))->toBe(1)
        ->and($b->recordFailure('c', 0, true))->toBe(T::None)
        ->and($b->isOpen('c'))->toBeFalse();
});

it('opens when the secondary fails too', function () {
    $b = authBreaker(new FakeClock, 1);
    $b->recordFailure('c', 0, true);

    expect($b->recordFailure('c', 1, true))->toBe(T::Opened);
});

it('re-grants a lost probe after another cooldown', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock, 1);
    $b->recordFailure('c', 0);
    $clock->advance(60);
    $b->check('c');
    $clock->advance(60);

    expect($b->check('c')->probe)->toBeTrue();
});
