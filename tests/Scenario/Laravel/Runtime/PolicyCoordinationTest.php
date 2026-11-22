<?php

declare(strict_types=1);

/*
 * Story: eight real PHP processes (proc_open, each booting a Testbench application on the same cold shared cache
 * store) hit the two policy features of ONE person at the same instant, with a frozen clock.
 *
 *  - Frequency cap: the person may receive 5 pushes in the window; each process asks the real FrequencyCapGate (built
 *    by the container from config) for 10 different sends. Pushes allowed in total must never exceed the cap.
 *  - Badge counter: each process increments the person's badge 25 times with a distinct effect key per increment: no
 *    lost update and no number handed out twice. Then all processes increment with the SAME effect key: once.
 *
 * The oracle is the sum over the processes plus the stored state read afterwards by a separate process. Stores as in
 * CacheCoordinationTest: redis (real redis-server), database on shared SQLite, database-server (mysql/mariadb/pgsql
 * when DB_CONNECTION names one), file, and memcached / dynamodb where the environment announces them.
 *
 * What a contended attempt becomes: both features retry a lost compare-and-set a bounded number of times, with a
 * short random pause. If they still lose, the badge counter throws BadgeStoreUnavailable (documented; nothing was
 * counted) and the cap gate, which never throws, DEFERS the destination for a few seconds (quota_exceeded, with a
 * hint): the cap could not be checked, so nothing is allowed, whatever `frequency.on_store_failure` says. That
 * setting is for a store that is down, not for a busy one.
 *
 * This scenario found the defect behind that rule: contention used to be read as a store failure, and with the
 * default `on_store_failure = allow` eight processes got 6 pushes through a cap of 5 and 61 through a cap of 60 on
 * the database store of PostgreSQL 16 (and on the file store under load).
 */

require_once __DIR__.'/CoordinationKit.php';

const CAP = 5;
const SENDS_EACH = 10;
const INCREMENTS_EACH = 25;

/**
 * Runs `$phases` (each a list of [mode, extra args]) one after the other against one cold shared store.
 *
 * @param  list<array{0: string, 1: array<string, mixed>, 2?: int}>  $phases
 * @return list<list<array<string, mixed>>>
 */
function runPolicyPhases(object $test, string $store, array $phases): array
{
    $dir = sys_get_temp_dir().'/mp-policy-race-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $redis = null;

    try {
        $args = ['appKey' => 'base64:'.base64_encode(random_bytes(32)), 'now' => '2026-11-20T10:00:00Z', 'limit' => CAP];
        $skip = prepareStore($store, $dir, $args, $redis);
        if ($skip !== null) {
            $test->markTestSkipped($skip);
        }
        $all = [];
        foreach ($phases as $phase) {
            $all[] = runContenders(['mode' => $phase[0]] + $phase[1] + $args, __DIR__.'/policy-contender.php', $phase[2] ?? CONTENDERS);
        }

        return $all;
    } finally {
        stopRedis($redis);
        removeTree($dir);
    }
}

/** @param  list<array<string, mixed>>  $results */
function tally(array $results, string $prefix): int
{
    $total = 0;
    foreach ($results as $line) {
        foreach ($line['tally'] as $key => $count) {
            if ($key === $prefix || str_starts_with($key, $prefix.':')) {
                $total += $count;
            }
        }
    }

    return $total;
}

function reportPolicy(string $label, string $store, string $text): void
{
    if (getenv('MP_RUNTIME_REPORT') !== false) {
        fwrite(STDERR, sprintf("[%s %s] %s\n", $label, $store, $text));
    }
}

const POLICY_STORES = ['redis', 'database', 'database-server', 'file', 'memcached', 'dynamodb'];

// A cap of 5 is used up in the first moments (most attempts are then refusals); a cap of 60 leaves most of the 80
// attempts as real writes to the same value, which is the heavier contention.
dataset('policy caps', static function (): Generator {
    foreach ([CAP, 60] as $cap) {
        foreach (POLICY_STORES as $store) {
            yield "{$store} cap {$cap}" => [$store, $cap];
        }
    }
});
const POLICY_CAPS = 'policy caps';

it('the frequency cap never lets more than the cap through when eight processes race for one person (on_store_failure = deny)', function (string $store, int $cap): void {
    [$race, $probe] = runPolicyPhases($this, $store, [
        ['frequency', ['attempts' => SENDS_EACH, 'onFailure' => 'deny', 'limit' => $cap]],
        ['read', ['attempts' => 1, 'onFailure' => 'deny', 'limit' => $cap], 1],
    ]);

    $allowed = tally($race, 'allowed');
    $capped = tally($race, 'capped');
    $contended = tally($race, 'contended');
    reportPolicy('cap/deny', $store, sprintf('cap=%d allowed=%d capped=%d contended(deferred)=%d', $cap, $allowed, $capped, $contended));

    expect(array_sum(array_column($race, 'exceptions')))->toBe(0)                  // the gate never throws
        ->and($allowed + $capped + $contended)->toBe(CONTENDERS * SENDS_EACH)      // every attempt got one of the three answers
        ->and($allowed)->toBeLessThanOrEqual($cap)                                  // never above the cap
        ->and($allowed)->toBeGreaterThan(0)
        ->and(tally($race, 'unavailable'))->toBe(0)                                 // the store is alive: nothing "fails closed"
        ->and(tally($race, 'capped:skipped/policy_frequency_capped'))->toBe($capped)
        ->and(tally($race, 'contended:deferred/quota_exceeded'))->toBe($contended);
    // The stored state agrees: a fresh process with a fresh effect key is refused once the cap is used up.
    if ($allowed === $cap) {
        expect(tally($probe, 'allowed'))->toBe(0);
    }
})->with(POLICY_CAPS);

it('the frequency cap never lets more than the cap through with the default on_store_failure = allow either', function (string $store, int $cap): void {
    [$race, $probe] = runPolicyPhases($this, $store, [
        ['frequency', ['attempts' => SENDS_EACH, 'onFailure' => 'allow', 'limit' => $cap]],
        ['read', ['attempts' => 1, 'onFailure' => 'deny', 'limit' => $cap], 1],
    ]);

    $allowed = tally($race, 'allowed');
    $capped = tally($race, 'capped');
    $contended = tally($race, 'contended');
    reportPolicy('cap/allow', $store, sprintf('cap=%d allowed=%d capped=%d contended(deferred)=%d (probe afterwards: %s)', $cap, $allowed, $capped, $contended, json_encode($probe[0]['tally'])));

    // Fail-open is for a store that is down. This one is alive and busy: a contended attempt is deferred, never let
    // through, so the default policy keeps the cap exactly like the strict one.
    expect(array_sum(array_column($race, 'exceptions')))->toBe(0)
        ->and($allowed + $capped + $contended)->toBe(CONTENDERS * SENDS_EACH)
        ->and($allowed)->toBeLessThanOrEqual($cap)
        ->and($allowed)->toBeGreaterThan(0)
        ->and(tally($race, 'contended:deferred/quota_exceeded'))->toBe($contended);
    if ($allowed === $cap) {
        expect(tally($probe, 'allowed'))->toBe(0);
    }
})->with(POLICY_CAPS);

it('the badge counter loses no increment and hands out no number twice when eight processes count for one person', function (string $store): void {
    [$race, $read] = runPolicyPhases($this, $store, [
        ['badge-distinct', ['attempts' => INCREMENTS_EACH]],
        ['read-badge', [], 1],
    ]);

    $values = array_merge(...array_column($race, 'values'));
    $contended = array_sum(array_column($race, 'contended'));
    reportPolicy('badge/distinct', $store, sprintf('counted=%d contended(gave up)=%d final=%d', count($values), $contended, $read[0]['current']));

    expect(array_merge(...array_column($race, 'unexpected')))->toBe([])             // only the documented exception, if any
        ->and(count($values) + $contended)->toBe(CONTENDERS * INCREMENTS_EACH)
        ->and(array_unique($values))->toHaveCount(count($values))                  // no number handed out twice
        ->and($read[0]['current'])->toBe(count($values))                           // no lost update, no phantom one
        ->and(max($values))->toBe(count($values));                                 // 1..n without gaps
    // A give-up counts nothing and says so; it is reported above. Without give-ups the badge is exactly 8 x 25.
    if ($contended === 0) {
        expect($read[0]['current'])->toBe(CONTENDERS * INCREMENTS_EACH);
    }
})->with(POLICY_STORES);

it('the badge counter moves once per effect key even when eight processes count the same effect at once', function (string $store): void {
    [$race, $read] = runPolicyPhases($this, $store, [
        ['badge-same', ['attempts' => 5]],
        ['read-badge', [], 1],
    ]);

    $values = array_merge(...array_column($race, 'values'));
    reportPolicy('badge/same', $store, sprintf('answers=%d contended(gave up)=%d final=%d', count($values), array_sum(array_column($race, 'contended')), $read[0]['current']));

    expect(array_merge(...array_column($race, 'unexpected')))->toBe([])
        ->and(array_unique($values))->toBe([$values[0] ?? 1])                      // everybody who got an answer got the same number
        ->and($values[0] ?? 1)->toBe(1)
        ->and($read[0]['current'])->toBe(1);                                       // the badge moved exactly once
})->with(POLICY_STORES);
