<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4).'/Scenario/Policy/Frequency/PolicyKit.php';

use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeStoreUnavailable;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\BusyGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\RecordingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\StoreDump;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\ThrowingGuardStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * The badge counter as the store sees it: bounds, TTLs, key layout, and how a damaged value is read back. Oracles are
 * the numbers returned and what a real InMemoryGuardStore holds.
 */

const BC_T0 = 1795168800; // 2026-11-20T10:00:00Z

/** @return array{0: BadgeCounter, 1: InMemoryGuardStore, 2: FakeClock} */
function badgeRig(int $ttl = 86400, int $markerTtl = 3600): array
{
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $store = new InMemoryGuardStore($clock);

    return [new BadgeCounter($store, $clock, $ttl, $markerTtl, maxAttempts: 4), $store, $clock];
}

it('refuses TTLs or attempts below one, and accepts one', function (int $ttl, int $markerTtl, int $attempts, bool $valid) {
    $clock = new FakeClock;
    $make = fn () => new BadgeCounter(new InMemoryGuardStore($clock), $clock, $ttl, $markerTtl, $attempts);

    if ($valid) {
        expect($make()->increment('s', 'u', 'e'))->toBe(1);
    } else {
        expect($make)->toThrow(InvalidArgumentException::class);
    }
})->with([
    'all one' => [1, 1, 1, true],
    'counter TTL zero' => [0, 60, 4, false],
    'marker TTL zero' => [60, 0, 4, false],
    'no attempt' => [60, 60, 0, false],
]);

it('tries 32 times by default on a live but busy store', function () {
    $clock = new FakeClock;
    $store = new BusyGuardStore(new InMemoryGuardStore($clock));

    expect(fn () => (new BadgeCounter($store, $clock))->increment('s', 'u', 'e'))->toThrow(BadgeStoreUnavailable::class, 'too contended')
        ->and($store->compareAndSets)->toBe(32);
});

it('writes the counter with its TTL: 90 days by default', function (?int $ttl, int $expected) {
    $clock = new FakeClock;
    $store = new RecordingGuardStore(new InMemoryGuardStore($clock));
    $counter = $ttl === null ? new BadgeCounter($store, $clock) : new BadgeCounter($store, $clock, $ttl);

    $counter->increment('s', 'u', 'e1');
    $counter->set('s', 'u', 5);
    $counter->reset('s', 'u');

    expect($store->writes)->toHaveCount(3);
    foreach ($store->writes as [, , $seconds]) {
        expect($seconds)->toBe($expected);
    }
})->with([
    'default' => [null, 90 * 86400],
    'configured' => [7 * 86400, 7 * 86400],
]);

it('forgets the counter after 90 idle days by default, not a second earlier', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $counter = new BadgeCounter(new InMemoryGuardStore($clock), $clock);
    $counter->increment('s', 'u', 'e1');

    $clock->advance(90 * 86400 - 1);
    expect($counter->current('s', 'u'))->toBe(1);
    $clock->advance(1);
    expect($counter->current('s', 'u'))->toBe(0);
});

it('remembers an effect for 3 days by default, not a second longer', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $counter = new BadgeCounter(new InMemoryGuardStore($clock), $clock);
    $counter->increment('s', 'u', 'e1');

    $clock->advance(3 * 86400 - 1);
    expect($counter->increment('s', 'u', 'e1'))->toBe(1);
    $clock->advance(1);
    expect($counter->increment('s', 'u', 'e1'))->toBe(2);
});

it('expires a marker exactly at its TTL', function (int $after, int $expected) {
    [$counter, , $clock] = badgeRig(markerTtl: 3600);
    $counter->increment('s', 'u', 'e1');
    $clock->advance($after);

    expect($counter->increment('s', 'u', 'e1'))->toBe($expected);
})->with([
    'one second before' => [3599, 1],
    'exactly at the TTL' => [3600, 2],
]);

it('keeps the markers of the newest 256 effects only', function () {
    [$counter, $store] = badgeRig();
    for ($i = 0; $i <= 256; $i++) {
        $counter->increment('s', 'u', 'e'.$i);   // 257 effects: e0 falls out
    }

    expect(StoreDump::decoded($store, 'badge:c:')['m'])->toHaveCount(256)
        ->and($counter->increment('s', 'u', 'e1'))->toBe(2)       // still known: its number again
        ->and($counter->increment('s', 'u', 'e0'))->toBe(258);    // forgotten: counts again (documented trade-off)
});

it('reads a counter it cannot reach as zero', function () {
    expect((new BadgeCounter(new ThrowingGuardStore, new FakeClock))->current('s', 'u'))->toBe(0);
});

it('never stores a negative number', function () {
    [$counter, $store] = badgeRig();
    $counter->increment('s', 'u', 'e1');
    $counter->set('s', 'u', -4);

    expect(StoreDump::decoded($store, 'badge:c:')['v'])->toBe(0);
});

it('stores a reset as zero', function () {
    [$counter, $store] = badgeRig();
    $counter->increment('s', 'u', 'e1');
    $counter->reset('s', 'u');

    expect(StoreDump::decoded($store, 'badge:c:')['v'])->toBe(0);
});

it('reads a damaged counter as zero and counts on from there, dropping malformed markers', function (string $raw, int $current) {
    [$counter, $store] = badgeRig();
    $counter->increment('s', 'u', 'seed');
    $key = StoreDump::onlyKey($store, 'badge:c:');
    $store->put($key, str_replace('NOW', (string) BC_T0, $raw), 3600);

    expect($counter->current('s', 'u'))->toBe($current)
        ->and($counter->increment('s', 'u', 'fresh'))->toBe($current + 1);
    $markers = StoreDump::decoded($store, 'badge:c:')['m'];
    expect($markers)->toHaveCount(1)
        ->and($markers[0][1])->toBe($current + 1)
        ->and($markers[0][2])->toBe(BC_T0);
})->with([
    'not json' => ['{{{', 0],
    'a json number' => ['7', 0],
    'negative value' => ['{"v":-5,"m":[]}', 0],
    'value as a string' => ['{"v":"7","m":[]}', 0],
    'no value' => ['{"m":[]}', 0],
    'markers of the wrong type' => ['{"v":3,"m":"x"}', 3],
    'marker: id is a number' => ['{"v":3,"m":[[123,1,NOW]]}', 3],
    'marker: number is a string' => ['{"v":3,"m":[["0123456789abcdef","1",NOW]]}', 3],
    'marker: time is a string' => ['{"v":3,"m":[["0123456789abcdef",1,"NOW"]]}', 3],
    'marker: no time' => ['{"v":3,"m":[["0123456789abcdef",1]]}', 3],
    'marker: no number' => ['{"v":3,"m":[{"0":"0123456789abcdef","2":NOW}]}', 3],
    'marker: no id' => ['{"v":3,"m":[{"1":1,"2":NOW}]}', 3],
    'marker: a string' => ['{"v":3,"m":["0123456789abcdef"]}', 3],
]);

it('stores each counter under a namespaced digest, never the raw subject or effect key', function () {
    [$counter, $store] = badgeRig();
    $counter->increment('tenant', 'o:4:User:alice@example.com', 'order-secret-key');

    $dump = StoreDump::of($store);

    expect(array_keys($dump))->toHaveCount(1)
        ->and(array_keys($dump)[0])->toMatch('/^badge:c:[0-9a-f]{64}$/')
        ->and(serialize($dump))->not->toContain('alice')->not->toContain('order-secret-key')
        ->and(json_decode(array_values($dump)[0], true)['m'][0][0])->toMatch('/^[0-9a-f]{16}$/');
});

it('keeps one counter per scope and subject, without ambiguity', function (array $first, array $second) {
    [$counter] = badgeRig();

    expect($counter->increment($first[0], $first[1], 'e1'))->toBe(1)
        ->and($counter->increment($second[0], $second[1], 'e2'))->toBe(1)
        ->and($counter->current($first[0], $first[1]))->toBe(1);
})->with([
    'another scope' => [['tenant-a', 'u'], ['tenant-b', 'u']],
    'another subject' => [['s', 'u1'], ['s', 'u2']],
    'scope|subject boundary' => [['a', 'bc'], ['ab', 'c']],
    'subject|scope shifted' => [['a', 'bc'], ['ca', 'b']],
]);

it('stores the marker of an un-keyed increment as a short random digest, a new one each time', function () {
    [$counter, $store] = badgeRig();
    $counter->increment('s', 'u', null);
    $counter->increment('s', 'u', null);

    $ids = array_map(static fn (array $m): string => $m[0], StoreDump::decoded($store, 'badge:c:')['m']);

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toMatch('/^[0-9a-f]{16}$/')
        ->and($ids[1])->toMatch('/^[0-9a-f]{16}$/')
        ->and($ids[0])->not->toBe($ids[1]);
});
