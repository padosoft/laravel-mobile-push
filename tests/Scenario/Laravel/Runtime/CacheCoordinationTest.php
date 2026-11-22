<?php

declare(strict_types=1);

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

/** A fresh cache table on the announced DynamoDB endpoint (Laravel's layout: string hash key "key"). */
function dynamoTable(string $endpoint, string $table): void
{
    $client = new DynamoDbClient([
        'region' => 'us-east-1', 'version' => 'latest', 'endpoint' => $endpoint,
        'credentials' => ['key' => 'local', 'secret' => 'local'],
    ]);
    $client->createTable([
        'TableName' => $table,
        'AttributeDefinitions' => [['AttributeName' => 'key', 'AttributeType' => 'S']],
        'KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']],
        'BillingMode' => 'PAY_PER_REQUEST',
    ]);
    $client->waitUntil('TableExists', ['TableName' => $table]);
}

function sharedSqlite(string $path): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE cache ("key" varchar NOT NULL PRIMARY KEY, "value" text NOT NULL, "expiration" integer NOT NULL)');
    $pdo->exec('CREATE TABLE cache_locks ("key" varchar NOT NULL PRIMARY KEY, "owner" varchar NOT NULL, "expiration" integer NOT NULL)');
}

/** Laravel's cache and lock tables (the layout of `php artisan make:cache-table`) on the default connection. */
function serverCacheTables(string $cache, string $locks): void
{
    Schema::create($cache, static function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Schema::create($locks, static function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
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
        } elseif ($store === 'database-server') {
            if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
                $this->markTestSkipped('Runs where DB_CONNECTION names a server engine (the `databases` CI job).');
            }
            // The contenders are other processes with the same DB_* environment: they open their own connections.
            $args['serverTables'] = ['cache' => 'mp_race_cache', 'locks' => 'mp_race_cache_locks'];
            serverCacheTables($args['serverTables']['cache'], $args['serverTables']['locks']);
        } elseif ($store === 'memcached') {
            $server = getenv('MOBILE_PUSH_TEST_MEMCACHED');
            if (! is_string($server) || $server === '' || ! extension_loaded('memcached')) {
                $this->markTestSkipped('Set MOBILE_PUSH_TEST_MEMCACHED=host:port (and load ext-memcached) to run the memcached store.');
            }
            [$host, $port] = explode(':', $server) + [1 => '11211'];
            $args['memcached'] = ['host' => $host, 'port' => (int) $port];
            // The server outlives the test: every run gets its own key prefix.
            $args['prefix'] = 'mp-race-'.bin2hex(random_bytes(5)).':';
        } elseif ($store === 'dynamodb') {
            $endpoint = getenv('MOBILE_PUSH_TEST_DYNAMODB');
            if (! is_string($endpoint) || $endpoint === '' || ! class_exists(DynamoDbClient::class)) {
                $this->markTestSkipped('Set MOBILE_PUSH_TEST_DYNAMODB=<endpoint> (and install aws/aws-sdk-php) to run the dynamodb store.');
            }
            $args['dynamodb'] = ['endpoint' => $endpoint, 'table' => 'mp_race_'.bin2hex(random_bytes(5))];
            dynamoTable($endpoint, $args['dynamodb']['table']);
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
