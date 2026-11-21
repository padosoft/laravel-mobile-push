<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\BreakerTransition as T;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\Millis;
use Padosoft\MobilePush\Core\Guard\StateCell;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function qualityBreaker(FakeClock $clock): DestinationQualityBreaker
{
    return new DestinationQualityBreaker(new InMemoryGuardStore($clock), $clock, minSample: 100, threshold: 0.5, cooldownSeconds: 60, probeSize: 10);
}

it('does not open below the minimum sample whatever the ratio', function () {
    $b = qualityBreaker(new FakeClock);

    expect($b->record('b', 99, 99))->toBe(T::None)->and($b->isOpen('b'))->toBeFalse();
});

it('opens once the sample is large enough and the ratio is strictly above the threshold', function () {
    $b = qualityBreaker(new FakeClock);
    $b->record('b', 50, 25);

    expect($b->record('b', 50, 25))->toBe(T::None)       // exactly 0.5: not above
        ->and($b->record('b', 10, 10))->toBe(T::Opened);
});

it('gates only bulk traffic while open, then probes a bounded batch', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);

    expect($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe(0)
        ->and($b->allow('b', 500, TrafficClass::Transactional)[0])->toBe(500);

    $clock->advance(60);
    [$granted, $transition] = $b->allow('b', 500, TrafficClass::Bulk);
    expect($granted)->toBe(10)->and($transition)->toBe(T::HalfOpened)
        ->and($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe(0);

    expect($b->record('b', 10, 1))->toBe(T::Closed)
        ->and($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe(500);
});

it('reopens when the probe batch is mostly bad', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);

    expect($b->record('b', 6, 6))->toBe(T::Reopened)->and($b->allow('b', 10, TrafficClass::Bulk)[0])->toBe(0);
});

it('counts quarantine as bad and ignores invalidations and non destination outcomes', function () {
    $d = static fn (SendStatus $s, DestinationAction $a = DestinationAction::None, bool $throttle = false) => new Decision($s, Reason::none(), destinationAction: $a, throttleProvider: $throttle);

    expect(DestinationQualityBreaker::classify($d(SendStatus::Rejected, DestinationAction::Quarantine)))->toBe(1)
        ->and(DestinationQualityBreaker::classify($d(SendStatus::Rejected, DestinationAction::Invalidate)))->toBeNull()
        ->and(DestinationQualityBreaker::classify($d(SendStatus::Accepted)))->toBe(0)
        ->and(DestinationQualityBreaker::classify($d(SendStatus::Failed, throttle: true)))->toBeNull()
        ->and(DestinationQualityBreaker::classify($d(SendStatus::OutcomeUnknown)))->toBeNull();
});

function qualityBreakerOn(InMemoryGuardStore $store, FakeClock $clock, int $probeSize = 10, int $maxSample = 2000): DestinationQualityBreaker
{
    return new DestinationQualityBreaker($store, $clock, minSample: 100, threshold: 0.5, cooldownSeconds: 60, probeSize: $probeSize, maxSample: $maxSample);
}

/** What `mobile-push:circuit` shows for a binding, read from the store the way the command reads it. */
function qualityAsCircuitSees(InMemoryGuardStore $store, string $binding): array
{
    $st = StateCell::read($store, 'mp:dest:'.$binding);

    return ['s' => $st['s'] ?? 0, 'n' => $st['n'] ?? 0, 'b' => $st['b'] ?? 0, 'at' => $st['at'] ?? null];
}

/**
 * Leaves the breaker half-open with the next allow() being the first of a probe batch; returns that batch's start.
 * 'fresh': the cooldown just ended. 'restarted': a batch went out one cooldown ago and its results never came back.
 * 'partial': a half-open state stored without probe counters.
 */
function qualityToProbe(string $path, InMemoryGuardStore $store, FakeClock $clock, DestinationQualityBreaker $b): DateTimeImmutable
{
    if ($path === 'partial') {
        $store->put('mp:dest:b', json_encode(['s' => 2, 'at' => Millis::of($clock->now())], JSON_THROW_ON_ERROR), 86400);

        return $clock->now();
    }
    $b->record('b', 100, 100);
    $clock->advance(60);
    if ($path === 'restarted') {
        $b->allow('b', 1, TrafficClass::Bulk);
        $clock->advance(60);
    }

    return $clock->now();
}

dataset('probe paths', ['fresh', 'restarted', 'partial']);

it('stores its state in the encoding mobile-push:circuit reads (0 closed, 1 open, 2 half-open)', function () {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);
    $b = qualityBreakerOn($store, $clock);
    $t0 = Millis::of($clock->now());

    $b->record('b', 50, 10);
    expect(qualityAsCircuitSees($store, 'b'))->toBe(['s' => 0, 'n' => 50, 'b' => 10, 'at' => null]);

    $b->record('b', 50, 50);
    expect(qualityAsCircuitSees($store, 'b'))->toBe(['s' => 1, 'n' => 0, 'b' => 0, 'at' => $t0])
        ->and($b->isOpen('b'))->toBeTrue();

    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);
    expect(qualityAsCircuitSees($store, 'b'))->toBe(['s' => 2, 'n' => 0, 'b' => 0, 'at' => $t0 + 60_000])
        ->and($b->isOpen('b'))->toBeTrue();

    $b->record('b', 10, 0);
    expect(qualityAsCircuitSees($store, 'b'))->toBe(['s' => 0, 'n' => 0, 'b' => 0, 'at' => null])
        ->and($b->isOpen('b'))->toBeFalse();
});

it('stays open until one millisecond before the cooldown ends and names that instant as retry time', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $opened = $clock->now();
    $b->record('b', 100, 100);

    $clock->set($opened->modify('+59999 milliseconds'));
    expect($b->allow('b', 500, TrafficClass::Bulk))->toEqual([0, T::None, $opened->modify('+60 seconds')]);

    $clock->set($opened->modify('+60 seconds'));
    expect($b->allow('b', 500, TrafficClass::Bulk))->toEqual([10, T::HalfOpened, null]);
});

it('grants one bounded probe batch across calls, then names the end of the batch cooldown', function (string $path, array $asks, array $granted) {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);
    $b = qualityBreakerOn($store, $clock);
    $start = qualityToProbe($path, $store, $clock, $b);

    $got = [];
    foreach ($asks as $i => $n) {
        if ($i === count($asks) - 1) {
            $clock->advance(30);   // asked later, still within the batch cooldown
        }
        [$g, , $retryAt] = $b->allow('b', $n, TrafficClass::Bulk);
        $got[] = $g;
        expect($retryAt)->toEqual($g === 0 ? $start->modify('+60 seconds') : null);
    }

    expect($got)->toBe($granted);
})->with('probe paths')->with([
    'one large ask' => [[500, 500], [10, 0]],
    'split asks' => [[4, 500, 500], [4, 6, 0]],
]);

it('starts a fresh probe batch at exactly one cooldown after the lost one, not before', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $batch = $clock->now();
    $b->allow('b', 500, TrafficClass::Bulk);

    $clock->set($batch->modify('+59999 milliseconds'));
    expect($b->allow('b', 500, TrafficClass::Bulk))->toEqual([0, T::None, $batch->modify('+60 seconds')]);

    $clock->set($batch->modify('+60 seconds'));
    expect($b->allow('b', 500, TrafficClass::Bulk))->toEqual([10, T::None, null]);
});

it('decides the probe batch: strictly more than half bad reopens, a full batch otherwise closes', function (string $path, array $records, array $transitions) {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);
    $b = qualityBreakerOn($store, $clock);
    qualityToProbe($path, $store, $clock, $b);
    $b->allow('b', 10, TrafficClass::Bulk);

    $got = [];
    foreach ($records as [$total, $bad]) {
        $got[] = $b->record('b', $total, $bad);
    }

    expect($got)->toBe($transitions);
})->with('probe paths')->with([
    'exactly half bad: closes' => [[[10, 5]], [T::Closed]],
    'six of ten bad, early: reopens' => [[[6, 6]], [T::Reopened]],
    'nine good is not yet a full batch' => [[[9, 0], [1, 0]], [T::None, T::Closed]],
    'bad outcomes add up across reports' => [[[5, 5], [1, 1]], [T::None, T::Reopened]],
]);

it('after reopening, probes again exactly one cooldown later', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);
    $clock->advance(5);
    $reopened = $clock->now();
    $b->record('b', 6, 6);

    $clock->set($reopened->modify('+59999 milliseconds'));
    expect($b->allow('b', 500, TrafficClass::Bulk))->toEqual([0, T::None, $reopened->modify('+60 seconds')]);

    $clock->set($reopened->modify('+60 seconds'));
    expect($b->allow('b', 500, TrafficClass::Bulk))->toEqual([10, T::HalfOpened, null]);
});

it('grants nothing more, never a negative count, when the probe size is lowered mid-batch', function () {
    $clock = new FakeClock;
    $store = new InMemoryGuardStore($clock);
    $before = qualityBreakerOn($store, $clock, probeSize: 20);
    $before->record('b', 100, 100);
    $clock->advance(60);
    expect($before->allow('b', 500, TrafficClass::Bulk)[0])->toBe(20);

    $after = qualityBreakerOn($store, $clock, probeSize: 10);   // redeployed with a smaller probe batch
    [$granted, , $retryAt] = $after->allow('b', 500, TrafficClass::Bulk);
    expect($granted)->toBe(0)->and($retryAt)->not->toBeNull();
});

it('after closing, counts a new sample from zero', function (int $total, int $bad, T $transition) {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);
    $b->record('b', 10, 0);

    expect($b->record('b', $total, $bad))->toBe($transition);
})->with([
    'minimum sample all bad: opens' => [100, 100, T::Opened],
    'one short of the minimum sample: stays closed' => [99, 99, T::None],
    'one above half: opens' => [100, 51, T::Opened],
    'exactly half: stays closed' => [100, 50, T::None],
]);

it('the sample window after a close starts at the close', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);
    $b->record('b', 10, 0);   // closed: the window starts now

    $clock->advance(300);
    expect($b->record('b', 99, 99))->toBe(T::None);
    $clock->advance(301);    // 601 s after the close: the window has expired
    expect($b->record('b', 1, 1))->toBe(T::None);
});

it('an empty report does not start the sample window', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    expect($b->record('b', 0, 0))->toBe(T::None);

    $clock->advance(300);
    $b->record('b', 99, 99);   // the window starts here
    $clock->advance(301);
    expect($b->record('b', 1, 1))->toBe(T::Opened);
});

it('halves the sample at the maximum size, keeping its ratio', function (int $total, int $bad, T $transition) {
    $clock = new FakeClock;
    $b = qualityBreakerOn(new InMemoryGuardStore($clock), $clock, maxSample: 200);
    expect($b->record('b', 200, 100))->toBe(T::None);   // exactly half; halved to 100 / 50

    expect($b->record('b', $total, $bad))->toBe($transition);
})->with([
    '90 bad of 200 after halving: stays closed' => [100, 40, T::None],
    '102 bad of 200 after halving: opens' => [100, 52, T::Opened],
]);

it('forgets a half-open state nobody touched for exactly one day', function (int $quietSeconds, int $granted) {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);

    $clock->advance($quietSeconds);
    expect($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe($granted);
})->with([
    'one second before the day ends: a new probe batch' => [86399, 10],
    'at the end of the day: closed again' => [86400, 500],
]);

it('stays open for a cooldown longer than a day (the stored state outlives the cooldown)', function () {
    $clock = new FakeClock;
    $b = new DestinationQualityBreaker(new InMemoryGuardStore($clock), $clock, minSample: 100, threshold: 0.5, cooldownSeconds: 2 * 86_400, probeSize: 10);
    $b->record('b', 100, 100);

    $clock->advance(86_400 + 3600);
    expect($b->isOpen('b'))->toBeTrue()->and($b->allow('b', 50, TrafficClass::Bulk)[0])->toBe(0);

    $clock->advance(86_400 - 3600);
    expect($b->allow('b', 50, TrafficClass::Bulk)[0])->toBe(10);
});
