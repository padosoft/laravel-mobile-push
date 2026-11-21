<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Frequency\BucketLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyDecision;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyStoreUnavailable;
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

it('throws a store failure only after the bounded attempts', function () {
    $counter = new FrequencyCounter(new SilentDeadGuardStore, new FakeClock, maxAttempts: 3);

    $counter->decide('s', 'u', 'b', BucketLimits::of(perDay: 1), 'e1');
})->throws(FrequencyStoreUnavailable::class);

it('round-trips decisions', function () {
    expect(FrequencyDecision::decode(FrequencyDecision::allowed()->encode())?->allowed)->toBeTrue()
        ->and(FrequencyDecision::decode('d:day:3')?->describeLimit())->toBe('3 per day')
        ->and(FrequencyDecision::decode('garbage'))->toBeNull()
        ->and(FrequencyDecision::decode(null))->toBeNull();
});
