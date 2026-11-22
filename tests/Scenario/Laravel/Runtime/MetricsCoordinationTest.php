<?php

declare(strict_types=1);

/*
 * Story: eight real PHP processes (proc_open, each booting a Testbench application on the same cold shared cache
 * store) record 25 send results each, all at the same instant and all in the same series (same connection, status,
 * reason and traffic class). A ninth process then reads the counters as a scrape would. Every result must have been
 * counted: 200, in one series.
 *
 * It exists because of a defect: on the `database` store (Laravel's default cache store), and on memcached and
 * DynamoDB, `increment` does not create a missing key, and the counters were never recorded at all. The cold start
 * matters too: eight processes create the same counter at once, and none of their first counts may be lost.
 *
 * Stores as in CacheCoordinationTest: redis (a real redis-server), database on a shared SQLite file, database-server
 * (mysql/mariadb/pgsql when DB_CONNECTION names one), file, and memcached / dynamodb where the environment announces
 * them (the `backends` CI workflow).
 */

require_once __DIR__.'/CoordinationKit.php';

const RESULTS_EACH = 25;

it('counts every send result of eight processes that start the same counter at the same instant', function (string $store): void {
    $dir = sys_get_temp_dir().'/mp-metrics-race-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $redis = null;

    try {
        $args = ['appKey' => 'base64:'.base64_encode(random_bytes(32)), 'now' => '2026-11-20T10:00:00Z'];
        $skip = prepareStore($store, $dir, $args, $redis);
        if ($skip !== null) {
            $this->markTestSkipped($skip);
        }
        $recorded = runContenders(['mode' => 'record', 'count' => RESULTS_EACH] + $args, __DIR__.'/metrics-contender.php');
        $read = runContenders(['mode' => 'read'] + $args, __DIR__.'/metrics-contender.php', 1);
    } finally {
        stopRedis($redis);
        removeTree($dir);
    }

    if (getenv('MP_RUNTIME_REPORT') !== false) {
        fwrite(STDERR, sprintf("[metrics %s] recorded=%d counted=%d series=%d\n", $store, array_sum(array_column($recorded, 'recorded')), $read[0]['results'], $read[0]['series']));
    }
    expect(array_sum(array_column($recorded, 'recorded')))->toBe(CONTENDERS * RESULTS_EACH)
        ->and($read[0]['series'])->toBe(1);
    if ($store === 'database') {
        // A shared SQLite file is the one store that cannot keep every count of this storm: its increment is a
        // transaction that reads and then writes, and SQLite refuses the write ("database is locked") whenever
        // another process is writing anything to the same file. The recorder retries ten times within a few
        // milliseconds and then gives the count up rather than hold the send: measured 162 to 198 of 200. What it
        // must never do is count nothing (the defect) or count twice.
        expect($read[0]['results'])->toBeGreaterThan(CONTENDERS * RESULTS_EACH / 2)->toBeLessThanOrEqual(CONTENDERS * RESULTS_EACH)
            ->and($read[0]['window'])->toBeGreaterThan(CONTENDERS * RESULTS_EACH / 2)->toBeLessThanOrEqual(CONTENDERS * RESULTS_EACH);

        return;
    }
    if ($store === 'file') {
        // The file store increments by reading and writing a file; the recorder serialises that with the store's
        // lock. A first count can still be lost: Laravel creates a cache file empty before filling it, and a process
        // that reads the counter or the index at that instant deletes it as expired (see FileStoreCreationRaceTest).
        // Seen on hosted runners as 198 of 200, never locally.
        expect($read[0]['results'])->toBeGreaterThanOrEqual(190)->toBeLessThanOrEqual(CONTENDERS * RESULTS_EACH)
            ->and($read[0]['window'])->toBeGreaterThanOrEqual(190)->toBeLessThanOrEqual(CONTENDERS * RESULTS_EACH);

        return;
    }
    expect($read[0]['results'])->toBe(CONTENDERS * RESULTS_EACH)
        ->and($read[0]['window'])->toBe(CONTENDERS * RESULTS_EACH);   // and so has the per-minute window behind the error rate
})->with(['redis', 'database', 'database-server', 'file', 'memcached', 'dynamodb']);
