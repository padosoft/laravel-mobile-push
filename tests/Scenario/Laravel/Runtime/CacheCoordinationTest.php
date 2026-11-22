<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;

/*
 * Story: eight worker processes (real PHP processes started with proc_open) start at the same instant on a cold cache.
 * All of them need the APNs provider token and all of them take from the same rate budget. Through the shared cache
 * store they must agree on ONE provider token (the compare-and-swap winner; the losers adopt it) and the permits
 * granted in total must stay within the budget. Exercised on every store that can be run in this container: a real
 * redis-server when its binary exists, the database store on a shared SQLite file, the file store on a shared
 * directory. The database store runs a second time on the server engine of the run (MySQL, MariaDB, PostgreSQL: the
 * `databases` CI job), where eight connections really write at once. Two more stores run where a server for them is announced by the environment (the `backends` CI
 * workflow): memcached (MOBILE_PUSH_TEST_MEMCACHED=host:port, ext-memcached) and DynamoDB (MOBILE_PUSH_TEST_DYNAMODB=
 * endpoint of a DynamoDB Local, aws/aws-sdk-php installed).
 */

require_once __DIR__.'/CoordinationKit.php';

const TAKES_EACH = 30;
const BUDGET = 100; // permits per minute = bucket capacity; the clock is frozen, so nothing refills

it('eight processes on a cold shared store agree on one provider token and stay within the rate budget', function (string $store): void {
    $dir = sys_get_temp_dir().'/mp-cache-race-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $redis = null;

    try {
        file_put_contents($dir.'/apns.p8', SendingWorld::ecPem('race'));
        $args = [
            'keyPath' => $dir.'/apns.p8',
            'appKey' => 'base64:'.base64_encode(random_bytes(32)),
            'now' => '2026-11-20T10:00:00Z',
            'takes' => TAKES_EACH,
            'rate' => BUDGET,
        ];
        $skip = prepareStore($store, $dir, $args, $redis);
        if ($skip !== null) {
            $this->markTestSkipped($skip);
        }

        $results = runContenders($args);
    } finally {
        stopRedis($redis);
        removeTree($dir);
    }

    $granted = array_sum(array_column($results, 'granted'));
    $contended = array_sum(array_column($results, 'contended'));
    if (getenv('MP_RUNTIME_REPORT') !== false) {
        fwrite(STDERR, sprintf("[%s] signatures=%d granted=%d contended=%d\n", $store, array_sum(array_column($results, 'signatures')), $granted, $contended));
    }
    expect($results)->toHaveCount(CONTENDERS)
        ->and(array_unique(array_column($results, 'token')))->toHaveCount(1)       // one token in use by everybody
        ->and(array_sum(array_column($results, 'signatures')))->toBeGreaterThanOrEqual(1)
        ->and($granted)->toBeLessThanOrEqual(BUDGET)                                // never above the budget
        ->and($granted + $contended)->toBeLessThanOrEqual(CONTENDERS * TAKES_EACH)
        ->and($granted)->toBeGreaterThan(BUDGET / 2);                               // and the budget is really used
    // Under this hot loop (8 processes, back-to-back takes, no network in between) some takes may give up with
    // "Guard state is too contended to update" on redis: measured and reported in docs/runtimes.md.
})->with(['redis', 'database', 'database-server', 'file', 'memcached', 'dynamodb']);

it('with the shared store unreachable, each process uses its own token and the rate limiter fails open: every take is granted, none throws', function (): void {
    $dir = sys_get_temp_dir().'/mp-cache-down-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);

    try {
        file_put_contents($dir.'/apns.p8', SendingWorld::ecPem('race'));
        $socket = stream_socket_server('tcp://127.0.0.1:0'); // a port with nothing listening once closed
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $results = runContenders([
            'store' => 'redis',
            'redisPort' => (int) substr($name, strrpos($name, ':') + 1),
            'keyPath' => $dir.'/apns.p8',
            'appKey' => 'base64:'.base64_encode(random_bytes(32)),
            'now' => '2026-11-20T10:00:00Z',
            'takes' => 3,
            'rate' => BUDGET,
        ]);
    } finally {
        removeTree($dir);
    }

    // Designed fallbacks: the token is reused locally (one signature per process); the limiter cannot coordinate
    // without the store, so it stops limiting instead of stopping the sends (StateCell fails open when the store
    // reports itself unavailable).
    expect(array_unique(array_column($results, 'token')))->toHaveCount(CONTENDERS)
        ->and(array_sum(array_column($results, 'signatures')))->toBe(CONTENDERS)
        ->and(array_sum(array_column($results, 'granted')))->toBe(CONTENDERS * 3)
        ->and(array_sum(array_column($results, 'contended')))->toBe(0);
});
