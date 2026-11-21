<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\BreakerTransition as T;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\Millis;
use Padosoft\MobilePush\Core\Guard\StateCell;
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

/** What `mobile-push:circuit` shows for a credential, read from the store the way the command reads it. */
function authAsCircuitSees(InMemoryGuardStore $store, string $credential): array
{
    $st = StateCell::read($store, 'mp:auth:'.$credential);

    return ['s' => $st['s'] ?? 0, 'f' => $st['f'] ?? 0, 'gen' => $st['gen'] ?? 0, 'at' => $st['at'] ?? null];
}

it('starts closed on the primary credential when nothing is stored', function () {
    $b = authBreaker(new FakeClock);
    $check = $b->check('fresh');

    expect($b->activeGeneration('fresh'))->toBe(0)
        ->and($b->isOpen('fresh'))->toBeFalse()
        ->and($check->allowed)->toBeTrue()->and($check->probe)->toBeFalse()->and($check->generation)->toBe(0);
});

it('stores its state in the encoding mobile-push:circuit reads (0 closed, 1 open, 2 half-open)', function () {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);
    $b = new AuthBreaker($store, $clock, 2, 60);
    $t0 = Millis::of($clock->now());

    $b->recordFailure('c', 0);
    expect(authAsCircuitSees($store, 'c'))->toBe(['s' => 0, 'f' => 1, 'gen' => 0, 'at' => null]);

    $b->recordFailure('c', 0);
    expect(authAsCircuitSees($store, 'c'))->toBe(['s' => 1, 'f' => 2, 'gen' => 0, 'at' => $t0])
        ->and($b->isOpen('c'))->toBeTrue();

    $clock->advance(60);
    $b->check('c');
    expect(authAsCircuitSees($store, 'c'))->toBe(['s' => 2, 'f' => 2, 'gen' => 0, 'at' => $t0 + 60_000])
        ->and($b->isOpen('c'))->toBeTrue();

    $b->recordFailure('c', 0);   // the probe failed
    expect(authAsCircuitSees($store, 'c'))->toBe(['s' => 1, 'f' => 2, 'gen' => 0, 'at' => $t0 + 60_000]);
});

it('stays open until one millisecond before the cooldown ends and names that instant as retry time', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock, 1, 60);
    $opened = $clock->now();
    $b->recordFailure('c', 0);

    $clock->set($opened->modify('+59999 milliseconds'));
    $denied = $b->check('c');
    expect($denied->allowed)->toBeFalse()->and($denied->probe)->toBeFalse()
        ->and($denied->retryAt)->toEqual($opened->modify('+60 seconds'));

    $clock->set($opened->modify('+60 seconds'));
    expect($b->check('c')->probe)->toBeTrue();
});

it('in half-open denies everyone else until the probe is overdue at exactly one cooldown, then re-grants one probe', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock, 1, 60);
    $b->recordFailure('c', 0);
    $clock->advance(60);
    $probedAt = $clock->now();
    $b->check('c');

    $clock->set($probedAt->modify('+59999 milliseconds'));
    $denied = $b->check('c');
    expect($denied->allowed)->toBeFalse()->and($denied->probe)->toBeFalse()
        ->and($denied->retryAt)->toEqual($probedAt->modify('+60 seconds'));

    $clock->set($probedAt->modify('+60 seconds'));
    $again = $b->check('c');
    expect($again->allowed)->toBeTrue()->and($again->probe)->toBeTrue()->and($again->transition)->toBe(T::None)
        ->and($b->check('c')->allowed)->toBeFalse();   // still one probe at a time
});

it('grants the probe of a half-open state stored without a probe marker, and only one', function () {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);
    $store->put('mp:auth:c', json_encode(['s' => 2, 'at' => Millis::of($clock->now())], JSON_THROW_ON_ERROR), 86400);
    $b = new AuthBreaker($store, $clock, 3, 60);

    $probe = $b->check('c');
    expect($probe->allowed)->toBeTrue()->and($probe->probe)->toBeTrue()
        ->and($b->check('c')->allowed)->toBeFalse();
});

it('after the probe closes it, opens again on exactly threshold consecutive failures', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock, 3, 60);
    foreach (range(1, 3) as $_) {
        $b->recordFailure('c', 0);
    }
    $clock->advance(60);
    $b->check('c');
    expect($b->recordSuccess('c', 0))->toBe(T::Closed);

    expect($b->recordFailure('c', 0))->toBe(T::None)
        ->and($b->recordFailure('c', 0))->toBe(T::None)
        ->and($b->recordFailure('c', 0))->toBe(T::Opened);
});

it('keeps the secondary credential through open, half-open, reopen and close, and ignores a stale success', function () {
    $clock = new FakeClock;
    $b = authBreaker($clock, 2, 60);
    $b->recordFailure('c', 0, true);
    expect($b->recordFailure('c', 0, true))->toBe(T::FailedOver);

    $b->recordFailure('c', 1, true);
    expect($b->recordFailure('c', 1, true))->toBe(T::Opened)
        ->and($b->activeGeneration('c'))->toBe(1)
        ->and($b->check('c')->generation)->toBe(1);

    $clock->advance(60);
    expect($b->check('c')->generation)->toBe(1)
        ->and($b->recordFailure('c', 1, true))->toBe(T::Reopened)
        ->and($b->activeGeneration('c'))->toBe(1);

    $clock->advance(60);
    $b->check('c');
    expect($b->recordSuccess('c', 0))->toBe(T::None)   // sent with the old primary: says nothing
        ->and($b->isOpen('c'))->toBeTrue()
        ->and($b->recordSuccess('c', 1))->toBe(T::Closed)
        ->and($b->activeGeneration('c'))->toBe(1)
        ->and($b->check('c')->generation)->toBe(1);
});

it('forgets consecutive failures exactly one day after the last one', function (int $quietSeconds, T $third) {
    $clock = new FakeClock;
    $b = authBreaker($clock, 3, 60);
    $b->recordFailure('c', 0);
    $b->recordFailure('c', 0);

    $clock->advance($quietSeconds);
    expect($b->recordFailure('c', 0))->toBe($third);
})->with([
    'one second before the day ends: still counted' => [86399, T::Opened],
    'at the end of the day: forgotten' => [86400, T::None],
]);
