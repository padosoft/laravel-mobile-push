<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;

/*
 * Story: eight worker processes (real PHP processes started with proc_open) start at the same instant on a cold cache.
 * All of them need the APNs provider token and all of them take from the same rate budget. Through the shared cache
 * store they must agree on ONE provider token (the compare-and-swap winner; the losers adopt it) and the permits
 * granted in total must stay within the budget. Exercised on every store that can be run in this container: a real
 * redis-server when its binary exists, the database store on a shared SQLite file, the file store on a shared
 * directory.
 */

const CONTENDERS = 8;
const TAKES_EACH = 30;
const BUDGET = 100; // permits per minute = bucket capacity; the clock is frozen, so nothing refills

/** @return array{process: resource, port: int}|null */
function startRedis(string $dir): ?array
{
    $binary = trim((string) shell_exec('command -v redis-server 2>/dev/null'));
    if ($binary === '' || ! extension_loaded('redis')) {
        return null;
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);
    $process = proc_open([$binary, '--port', (string) $port, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--dir', $dir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir.'/redis.log', 'a'], 2 => ['file', $dir.'/redis.log', 'a']], $pipes);
    if (! is_resource($process)) {
        return null;
    }
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        try {
            $redis = new Redis;
            if (@$redis->connect('127.0.0.1', $port, 0.2) && $redis->ping()) {
                $redis->close();

                return ['process' => $process, 'port' => $port];
            }
        } catch (Throwable) {
        }
        usleep(20_000);
    }
    proc_terminate($process);
    proc_close($process);

    return null;
}

function sharedSqlite(string $path): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE cache ("key" varchar NOT NULL PRIMARY KEY, "value" text NOT NULL, "expiration" integer NOT NULL)');
    $pdo->exec('CREATE TABLE cache_locks ("key" varchar NOT NULL PRIMARY KEY, "owner" varchar NOT NULL, "expiration" integer NOT NULL)');
}

function removeTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * Start the contenders together and collect their JSON lines.
 *
 * @param  array<string, mixed>  $args
 * @return list<array{signatures: int, token: string, granted: int, contended: int}>
 */
function runContenders(array $args): array
{
    $script = __DIR__.'/cache-contender.php';
    $args['startAt'] = microtime(true) + 2.0; // every contender boots first, then all go at once
    $procs = [];
    for ($i = 0; $i < CONTENDERS; $i++) {
        $proc = proc_open([PHP_BINARY, $script, json_encode($args, JSON_THROW_ON_ERROR)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('cannot start a contender');
        }
        $procs[] = [$proc, $pipes];
    }
    $results = [];
    foreach ($procs as [$proc, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $line = json_decode(trim($out), true);
        if ($code !== 0 || ! is_array($line)) {
            throw new RuntimeException("contender failed ({$code}): ".substr($out.$err, 0, 2000));
        }
        $results[] = $line;
    }

    return $results;
}

it('eight processes on a cold shared store agree on one provider token and stay within the rate budget', function (string $store): void {
    $dir = sys_get_temp_dir().'/mp-cache-race-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $redis = null;

    try {
        file_put_contents($dir.'/apns.p8', SendingWorld::ecPem('race'));
        $args = [
            'store' => $store,
            'keyPath' => $dir.'/apns.p8',
            'appKey' => 'base64:'.base64_encode(random_bytes(32)),
            'now' => '2026-11-20T10:00:00Z',
            'takes' => TAKES_EACH,
            'rate' => BUDGET,
        ];
        if ($store === 'redis') {
            $redis = startRedis($dir);
            if ($redis === null) {
                $this->markTestSkipped('redis-server (and ext-redis) are needed for the redis store.');
            }
            $args['redisPort'] = $redis['port'];
        } elseif ($store === 'database') {
            $args['sqlitePath'] = $dir.'/shared.sqlite';
            sharedSqlite($args['sqlitePath']);
        } else {
            $args['cacheDir'] = $dir.'/cache';
            mkdir($args['cacheDir'], 0700);
        }

        $results = runContenders($args);
    } finally {
        if ($redis !== null) {
            proc_terminate($redis['process']);
            proc_close($redis['process']);
        }
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
})->with(['redis', 'database', 'file']);

it('KNOWN LIMITATION: with the shared store unreachable, each process uses its own token and the rate limiter gives up with an exception', function (): void {
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

    // Designed fallback for the token (local reuse, one signature per process); for the limiter the cache failure
    // surfaces as StateCell's "Guard state is too contended to update" RuntimeException on every take.
    expect(array_unique(array_column($results, 'token')))->toHaveCount(CONTENDERS)
        ->and(array_sum(array_column($results, 'signatures')))->toBe(CONTENDERS)
        ->and(array_sum(array_column($results, 'granted')))->toBe(0)
        ->and(array_sum(array_column($results, 'contended')))->toBe(CONTENDERS * 3);
});
