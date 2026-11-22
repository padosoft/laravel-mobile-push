<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4).'/Scenario/Policy/Frequency/PolicyKit.php';

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Frequency\BucketLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyDecision;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyStoreContended;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyStoreUnavailable;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\BusyGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\SilentDeadGuardStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function counter(?FakeClock &$clock = null, ?InMemoryGuardStore &$store = null): FrequencyCounter
{
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $store = new InMemoryGuardStore($clock);

    return new FrequencyCounter($store, $clock);
}

it('frees the unit exactly when the first effect leaves the window', function () {
    $counter = counter($clock);
    $limits = BucketLimits::of(perHour: 1);

    expect($counter->decide('s', 'u', 'b', $limits, 'e1')->allowed)->toBeTrue();
    $clock->advance(3599);
    expect($counter->decide('s', 'u', 'b', $limits, 'e2')->allowed)->toBeFalse();
    $clock->advance(1);
    expect($counter->decide('s', 'u', 'b', $limits, 'e3')->allowed)->toBeTrue();
});

it('does not touch the store for an unlimited bucket', function () {
    $counter = new FrequencyCounter(new SilentDeadGuardStore, new FakeClock);

    expect($counter->decide('s', 'u', 'b', BucketLimits::unlimited(), 'e1')->allowed)->toBeTrue();
});

it('keeps bounded state however many effects are capped', function () {
    $counter = counter($clock, $store);
    $limits = BucketLimits::of(perDay: 2);
    for ($i = 0; $i < 1000; $i++) {
        $counter->decide('s', 'u', 'b', $limits, 'effect-'.$i);
    }

    $stateKeys = array_filter(array_keys((fn () => $this->data)->call($store)), fn ($k) => str_starts_with($k, 'freq:s:'));
    $state = json_decode((string) $store->get(array_values($stateKeys)[0]), true);

    expect($stateKeys)->toHaveCount(1)->and(count($state['a']))->toBe(2)->and(count($state['c']))->toBeLessThanOrEqual(256);
});

it('answers a long-evicted capped effect from the decision key', function () {
    $counter = counter($clock);
    $limits = BucketLimits::of(perDay: 1);
    $counter->decide('s', 'u', 'b', $limits, 'first');
    expect($counter->decide('s', 'u', 'b', $limits, 'victim')->allowed)->toBeFalse();
    for ($i = 0; $i < 400; $i++) { // pushes 'victim' out of the bounded capped list
        $counter->decide('s', 'u', 'b', $limits, 'noise-'.$i);
    }

    expect($counter->decide('s', 'u', 'b', $limits, 'victim')->allowed)->toBeFalse();
});

it('reports a store that says it is down as a store failure, on the first lost write', function () {
    $store = new SilentDeadGuardStore;
    $counter = new FrequencyCounter($store, new FakeClock, maxAttempts: 3);

    expect(fn () => $counter->decide('s', 'u', 'b', BucketLimits::of(perDay: 1), 'e1'))->toThrow(FrequencyStoreUnavailable::class)
        ->and($store->compareAndSets)->toBe(1);
});

/*
 * Found by eight real processes on a database cache store (PolicyCoordinationTest): a worker that kept losing the
 * compare-and-set was told "store failure", and the default failure policy let its push through: 6 from a cap of 5.
 */
it('reports a live store whose writes keep losing as contention, after exactly the bounded attempts', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $store = new BusyGuardStore(new InMemoryGuardStore($clock));
    $counter = new FrequencyCounter($store, $clock, maxAttempts: 3);

    expect(fn () => $counter->decide('s', 'u', 'b', BucketLimits::of(perDay: 1), 'e1'))->toThrow(FrequencyStoreContended::class)
        ->and($store->compareAndSets)->toBe(3);
});

it('decides once when the write is lost a few times and then lands', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $inner = new InMemoryGuardStore($clock);
    $store = new BusyGuardStore($inner, losses: 4);
    $counter = new FrequencyCounter($store, $clock);
    $limits = BucketLimits::of(perDay: 1);

    expect($counter->decide('s', 'u', 'b', $limits, 'e1')->allowed)->toBeTrue()
        ->and($store->compareAndSets)->toBe(6)   // four lost, the state, the decision key
        ->and((new FrequencyCounter($inner, $clock))->decide('s', 'u', 'b', $limits, 'e2')->allowed)->toBeFalse();
});

it('treats a store that cannot say whether it is alive as contended, never as down', function () {
    $store = new class implements GuardStore
    {
        public function get(string $key): ?string
        {
            return null;
        }

        public function put(string $key, string $value, int $ttlSeconds): void {}

        public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
        {
            return false;
        }
    };

    (new FrequencyCounter($store, new FakeClock, maxAttempts: 2))->decide('s', 'u', 'b', BucketLimits::of(perDay: 1), 'e1');
})->throws(FrequencyStoreContended::class);

it('round-trips decisions', function () {
    expect(FrequencyDecision::decode(FrequencyDecision::allowed()->encode())?->allowed)->toBeTrue()
        ->and(FrequencyDecision::decode('d:day:3')?->describeLimit())->toBe('3 per day')
        ->and(FrequencyDecision::decode('garbage'))->toBeNull()
        ->and(FrequencyDecision::decode(null))->toBeNull();
});
