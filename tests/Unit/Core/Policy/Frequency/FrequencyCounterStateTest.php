<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4).'/Scenario/Policy/Frequency/PolicyKit.php';

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Frequency\BucketLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyDecision;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyStoreContended;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\BusyGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\EvictingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\PrefixInterleavingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\RecordingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\StoreDump;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * The frequency counter as the store sees it: what is kept, for how long, under which keys, and how a damaged or
 * partially evicted state is read back. Every oracle is the decision returned or what a real InMemoryGuardStore holds.
 */

const FC_T0 = 1795168800; // 2026-11-20T10:00:00Z

/** @return array{0: FrequencyCounter, 1: InMemoryGuardStore, 2: FakeClock} */
function frequencyRig(?callable $wrap = null): array
{
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $store = new InMemoryGuardStore($clock);
    $front = $wrap === null ? $store : $wrap($store);
    assert($front instanceof GuardStore);

    return [new FrequencyCounter($front, $clock, maxAttempts: 4), $store, $clock];
}

/** @return array{a: list<mixed>, c: list<mixed>} */
function frequencyState(InMemoryGuardStore $store): array
{
    $state = StoreDump::decoded($store, 'freq:s:');

    return ['a' => $state['a'] ?? [], 'c' => $state['c'] ?? []];
}

it('stores every effect id as a short digest, and every entry in a fixed shape, as plain lists', function (?string $keyPrefix) {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perDay: 2);
    foreach (range(1, 5) as $i) {
        $counter->decide('s', 'u', 'b', $limits, $keyPrefix === null ? null : $keyPrefix.$i);
        $clock->advance(60);
    }

    $state = frequencyState($store);

    expect(array_is_list($state['a']))->toBeTrue()
        ->and(array_is_list($state['c']))->toBeTrue()
        ->and($state['a'])->toHaveCount(2)
        ->and($state['c'])->toHaveCount(3)
        ->and(array_map(static fn (array $e): int => $e[1], $state['a']))->toBe([FC_T0, FC_T0 + 60])
        ->and(array_map(static fn (array $e): array => [$e[1], $e[2], $e[3]], $state['c']))->toBe([
            [FC_T0 + 120, 'day', 2], [FC_T0 + 180, 'day', 2], [FC_T0 + 240, 'day', 2],
        ]);
    foreach ([...$state['a'], ...$state['c']] as $entry) {
        expect($entry[0])->toBeString()->toMatch('/^[0-9a-f]{16}$/');
    }
})->with([
    'with effect keys' => ['campaign-'],
    'without effect keys' => [null],
]);

it('drops an allowed effect from the state the very second it leaves the longest window', function () {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perHour: 2);

    $counter->decide('s', 'u', 'b', $limits, 'first');    // T0
    $clock->advance(1800);
    $counter->decide('s', 'u', 'b', $limits, 'second');   // T0 + 1800
    $clock->advance(1800);
    expect($counter->decide('s', 'u', 'b', $limits, 'third')->allowed)->toBeTrue();   // T0 + 3600: 'first' is out

    $state = frequencyState($store);
    expect(array_map(static fn (array $e): int => $e[1], $state['a']))->toBe([FC_T0 + 1800, FC_T0 + 3600])
        ->and(array_is_list($state['a']))->toBeTrue();
});

it('keeps exactly the newest 256 capped effects', function () {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perDay: 1);
    $counter->decide('s', 'u', 'b', $limits, 'sent');
    for ($i = 1; $i <= 300; $i++) {
        $clock->advance(1);
        $counter->decide('s', 'u', 'b', $limits, 'capped-'.$i);
    }

    $times = array_map(static fn (array $e): int => $e[1], frequencyState($store)['c']);

    expect($times)->toHaveCount(256)
        ->and($times[0])->toBe(FC_T0 + 45)
        ->and($times[255])->toBe(FC_T0 + 300);
});

it('keeps at most the largest limit of allowed entries over weeks of traffic', function () {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perHour: 2, perDay: 3);

    for ($i = 0; $i < 60; $i++) {
        $counter->decide('s', 'u', 'b', $limits, 'e'.$i);
        $clock->advance(5 * 3600);
    }

    expect(count(frequencyState($store)['a']))->toBeLessThanOrEqual(3);
});

it('forgets a capped effect exactly when its decision would expire, and keeps the newer ones', function () {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perHour: 1); // longest window 3600 s: capped ids are kept 3600 + 3600 s

    $counter->decide('s', 'u', 'b', $limits, 'allowed');
    $clock->advance(1);
    $counter->decide('s', 'u', 'b', $limits, 'old-capped');      // at T0 + 1
    $clock->advance(99);
    $counter->decide('s', 'u', 'b', $limits, 'newer-capped');    // at T0 + 100, keeps the state alive
    $clock->set('@'.(FC_T0 + 1 + 7200));                          // exactly 7200 s after the old one

    expect($counter->decide('s', 'u', 'b', $limits, 'fresh')->allowed)->toBeTrue();
    $state = frequencyState($store);
    expect(array_is_list($state['c']))->toBeTrue()
        ->and(array_map(static fn (array $e): int => $e[1], $state['c']))->toBe([FC_T0 + 100]);
});

it('names the shortest full window when several are full', function () {
    [$counter] = frequencyRig();
    $limits = BucketLimits::of(perHour: 1, perDay: 1);

    $counter->decide('s', 'u', 'b', $limits, 'e1');
    $capped = $counter->decide('s', 'u', 'b', $limits, 'e2');

    expect($capped->allowed)->toBeFalse()->and($capped->window)->toBe('hour')->and($capped->limit)->toBe(1);
});

/*
 * The decision key is an optimisation for retries long after the window; the state value alone must already keep
 * the once-per-effect promise. A cache that evicts the decision keys (LRU under memory pressure) must not change
 * any answer while the state value is still there.
 */
it('keeps a capped effect capped from the state alone when the decision keys were evicted, even once the window has room', function () {
    [$counter, $store, $clock] = frequencyRig(static fn (GuardStore $s): GuardStore => new EvictingGuardStore($s, 'freq:d:'));
    $limits = BucketLimits::of(perHour: 1);

    $counter->decide('s', 'u', 'b', $limits, 'first');
    $clock->advance(10);
    $capped = $counter->decide('s', 'u', 'b', $limits, 'victim');
    $clock->advance(3600);   // 'first' left the window: a NEW effect has room now

    $retry = $counter->decide('s', 'u', 'b', $limits, 'victim');

    expect($capped->describeLimit())->toBe('1 per hour')
        ->and($retry->allowed)->toBeFalse()
        ->and($retry->window)->toBe('hour')
        ->and($retry->limit)->toBe(1)
        ->and($counter->decide('s', 'u', 'b', $limits, 'new')->allowed)->toBeTrue()
        ->and(StoreDump::keysStartingWith($store, 'freq:d:'))->toBe([]);
});

it('answers a retry of an allowed effect from the state alone, consuming nothing, when the decision keys were evicted', function () {
    [$counter] = frequencyRig(static fn (GuardStore $s): GuardStore => new EvictingGuardStore($s, 'freq:d:'));
    $limits = BucketLimits::of(perDay: 2);

    expect($counter->decide('s', 'u', 'b', $limits, 'e1')->allowed)->toBeTrue()
        ->and($counter->decide('s', 'u', 'b', $limits, 'e1')->allowed)->toBeTrue()
        ->and($counter->decide('s', 'u', 'b', $limits, 'e2')->allowed)->toBeTrue()
        ->and($counter->decide('s', 'u', 'b', $limits, 'e3')->allowed)->toBeFalse();
});

it('answers a retry after the window from the decision key without consuming the room a new effect needs', function () {
    [$counter, , $clock] = frequencyRig();
    $limits = BucketLimits::of(perHour: 1);

    $counter->decide('s', 'u', 'b', $limits, 'e1');
    $clock->advance(3601);

    expect($counter->decide('s', 'u', 'b', $limits, 'e1')->allowed)->toBeTrue()   // the stored answer
        ->and($counter->decide('s', 'u', 'b', $limits, 'e2')->allowed)->toBeTrue(); // the room is still there
});

it('gives the answer another worker stored first for the effect, not its own', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $inner = new InMemoryGuardStore($clock);
    $store = new PrefixInterleavingGuardStore($inner, 'freq:d:');
    $counter = new FrequencyCounter($store, $clock, maxAttempts: 4);
    // Between this worker's state update and its decision write, another worker's decision for the same effect lands.
    $store->hook = static function (string $decisionKey) use ($inner): void {
        $inner->put($decisionKey, FrequencyDecision::capped('day', 7)->encode(), 3600);
    };

    $decision = $counter->decide('s', 'u', 'b', BucketLimits::of(perDay: 7), 'e1');

    expect($decision->allowed)->toBeFalse()->and($decision->describeLimit())->toBe('7 per day');
});

it('separates scope, subject and bucket without ambiguity', function (array $first, array $second) {
    [$counter] = frequencyRig();
    $limits = BucketLimits::of(perDay: 1);

    expect($counter->decide($first[0], $first[1], $first[2], $limits, 'e1')->allowed)->toBeTrue()
        ->and($counter->decide($second[0], $second[1], $second[2], $limits, 'e2')->allowed)->toBeTrue();
})->with([
    'another scope' => [['tenant-a', 'u', 'b'], ['tenant-b', 'u', 'b']],
    'another subject' => [['s', 'u1', 'b'], ['s', 'u2', 'b']],
    'another bucket' => [['s', 'u', 'marketing'], ['s', 'u', 'orders']],
    'scope|subject boundary' => [['a', 'bc', 'x'], ['ab', 'c', 'x']],
    'subject|scope shifted' => [['a', 'bc', 'x'], ['ca', 'b', 'x']],
    'subject|bucket boundary' => [['s', 'a', 'bc'], ['s', 'ab', 'c']],
    'bucket|scope shifted' => [['a', 'u', 'xb'], ['ba', 'u', 'x']],
]);

it('keeps the decision of an effect per person: the same effect key capped for one is not capped for another', function () {
    [$counter] = frequencyRig();
    $limits = BucketLimits::of(perDay: 1);

    $counter->decide('s', 'alice', 'b', $limits, 'earlier');
    $alice = $counter->decide('s', 'alice', 'b', $limits, 'campaign');
    $bob = $counter->decide('s', 'bob', 'b', $limits, 'campaign');

    expect($alice->allowed)->toBeFalse()->and($bob->allowed)->toBeTrue();
});

it('namespaces its keys and stores only digests in them', function () {
    [$counter, $store] = frequencyRig();
    $counter->decide('tenant', 'o:4:User:42', 'marketing', BucketLimits::of(perDay: 1), 'campaign-1');

    $keys = array_keys(StoreDump::of($store));
    sort($keys);

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toMatch('/^freq:d:[0-9a-f]{64}$/')
        ->and($keys[1])->toMatch('/^freq:s:[0-9a-f]{64}$/');
});

it('keeps the state and every decision for the longest window plus one hour', function (BucketLimits $limits, int $ttl) {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $store = new RecordingGuardStore(new InMemoryGuardStore($clock));
    $counter = new FrequencyCounter($store, $clock);

    $counter->decide('s', 'u', 'b', $limits, 'e1');
    $counter->decide('s', 'u', 'b', $limits, 'e2');
    $counter->decide('s', 'u', 'b', $limits, null);

    expect($store->writes)->not->toBeEmpty();
    foreach ($store->writes as [$method, $key, $seconds]) {
        expect($seconds)->toBe($ttl, "{$method} {$key}");
    }
})->with([
    'per hour only' => [BucketLimits::of(perHour: 1), 3600 + 3600],
    'per day' => [BucketLimits::of(perDay: 1), 86400 + 3600],
    'both' => [BucketLimits::of(perHour: 1, perDay: 5), 86400 + 3600],
]);

it('still answers a capped retry one second before the decision expires, and treats it as new one second later', function () {
    [$counter, , $clock] = frequencyRig();
    $limits = BucketLimits::of(perHour: 1);

    $counter->decide('s', 'u', 'b', $limits, 'first');           // T0
    $clock->advance(10);
    $counter->decide('s', 'u', 'b', $limits, 'victim');          // capped at T0 + 10, kept 7200 s
    $clock->advance(7199);
    expect($counter->decide('s', 'u', 'b', $limits, 'victim')->allowed)->toBeFalse();
    $clock->advance(1);
    expect($counter->decide('s', 'u', 'b', $limits, 'victim')->allowed)->toBeTrue();
});

/*
 * A stored value can be damaged (an older format, a manual edit, a half-written value). It must be read as "less
 * known", never crash and never count garbage as a sent effect; the next write keeps only well-formed entries.
 */
it('reads a damaged state as less known, never counts garbage, and rewrites it clean', function (string $raw) {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perDay: 1);
    $counter->decide('s', 'u', 'b', $limits, null);  // creates the state key, then damage it
    $stateKey = StoreDump::onlyKey($store, 'freq:s:');
    $store->put($stateKey, str_replace('NOW', (string) FC_T0, $raw), 3600);
    $clock->advance(60);

    $decision = $counter->decide('s', 'u', 'b', $limits, 'fresh');
    $state = frequencyState($store);

    expect($decision->allowed)->toBeTrue()
        ->and($state['a'])->toHaveCount(1)
        ->and($state['a'][0][1])->toBe(FC_T0 + 60)
        ->and($state['c'])->toBe([]);
})->with([
    'not json' => ['{{{'],
    'a json string' => ['"a"'],
    'a json number' => ['42'],
    'lists of the wrong type' => ['{"a":"x","c":5}'],
    'allowed entry: id is a number' => ['{"a":[[123,NOW]],"c":[]}'],
    'allowed entry: time is a string' => ['{"a":[["0123456789abcdef","NOW"]],"c":[]}'],
    'allowed entry: no time' => ['{"a":[["0123456789abcdef"]],"c":[]}'],
    'allowed entry: no id' => ['{"a":[{"1":NOW}],"c":[]}'],
    'allowed entry: null id' => ['{"a":[[null,NOW]],"c":[]}'],
    'allowed entry: a string' => ['{"a":["0123456789abcdef"],"c":[]}'],
    'capped entry: id is a number' => ['{"a":[],"c":[[123,NOW,"day",1]]}'],
    'capped entry: time is a string' => ['{"a":[],"c":[["0123456789abcdef","NOW","day",1]]}'],
    'capped entry: window is a number' => ['{"a":[],"c":[["0123456789abcdef",NOW,5,1]]}'],
    'capped entry: limit is a string' => ['{"a":[],"c":[["0123456789abcdef",NOW,"day","1"]]}'],
    'capped entry: no limit' => ['{"a":[],"c":[["0123456789abcdef",NOW,"day"]]}'],
    'capped entry: no window' => ['{"a":[],"c":[{"0":"0123456789abcdef","1":NOW,"3":1}]}'],
    'capped entry: no time' => ['{"a":[],"c":[{"0":"0123456789abcdef","2":"day","3":1}]}'],
    'capped entry: no id' => ['{"a":[],"c":[{"1":NOW,"2":"day","3":1}]}'],
    'capped entry: a string' => ['{"a":[],"c":["0123456789abcdef"]}'],
]);

it('reads only the part of a state that is damaged as unknown', function () {
    [$counter, $store, $clock] = frequencyRig();
    $limits = BucketLimits::of(perDay: 1);
    $counter->decide('s', 'u', 'b', $limits, 'sent');          // T0: the only unit of the day
    $clock->advance(30);
    $counter->decide('s', 'u', 'b', $limits, 'capped-once');   // T0 + 30
    $stateKey = StoreDump::onlyKey($store, 'freq:s:');
    $state = json_decode((string) $store->get($stateKey), true);
    $store->put($stateKey, json_encode(['a' => $state['a'], 'c' => 'damaged']), 3600);
    $clock->advance(30);

    expect($counter->decide('s', 'u', 'b', $limits, 'another')->allowed)->toBeFalse()   // the allowed list still counts
        ->and(frequencyState($store)['c'])->toHaveCount(1);
});

it('refuses a counter that may never try', function () {
    new FrequencyCounter(new InMemoryGuardStore(new FakeClock), new FakeClock, maxAttempts: 0);
})->throws(InvalidArgumentException::class);

it('tries exactly once when allowed a single attempt', function () {
    $clock = new FakeClock;
    $store = new BusyGuardStore(new InMemoryGuardStore($clock));

    expect(fn () => (new FrequencyCounter($store, $clock, maxAttempts: 1))->decide('s', 'u', 'b', BucketLimits::of(perDay: 1), 'e1'))
        ->toThrow(FrequencyStoreContended::class)
        ->and($store->compareAndSets)->toBe(1);
});

it('tries 32 times by default before calling the store contended, as the docs say', function () {
    $clock = new FakeClock;
    $store = new BusyGuardStore(new InMemoryGuardStore($clock));

    expect(fn () => (new FrequencyCounter($store, $clock))->decide('s', 'u', 'b', BucketLimits::of(perDay: 1), 'e1'))
        ->toThrow(FrequencyStoreContended::class)
        ->and($store->compareAndSets)->toBe(32);
});
