<?php

declare(strict_types=1);

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Helpers shared by the multi-process coordination scenarios (CacheCoordinationTest, PolicyCoordinationTest): a real
 * redis-server, a shared SQLite file, the cache tables on a server engine, a DynamoDB Local table, and the runner that
 * starts the contender processes at the same instant.
 */

if (! defined('CONTENDERS')) {
    define('CONTENDERS', 8);
}

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
 * @return list<array<string, mixed>>
 */
function runContenders(array $args, string $script = __DIR__.'/cache-contender.php', int $count = CONTENDERS): array
{
    $args['startAt'] = microtime(true) + 2.0; // every contender boots first, then all go at once
    $procs = [];
    for ($i = 0; $i < $count; $i++) {
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

/**
 * Prepares the shared store named `$store` inside `$dir` and adds what the contenders need to `$args`.
 * Returns a skip message when the store cannot run here (null = ready). A started redis-server is put in `$redis`.
 *
 * @param  array<string, mixed>  $args
 * @param  array{process: resource, port: int}|null  $redis
 */
function prepareStore(string $store, string $dir, array &$args, ?array &$redis): ?string
{
    $args['store'] = $store;
    if ($store === 'redis') {
        $redis = startRedis($dir);
        if ($redis === null) {
            return 'redis-server (and ext-redis) are needed for the redis store.';
        }
        $args['redisPort'] = $redis['port'];
    } elseif ($store === 'database') {
        $args['sqlitePath'] = $dir.'/shared.sqlite';
        sharedSqlite($args['sqlitePath']);
    } elseif ($store === 'database-server') {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return 'Runs where DB_CONNECTION names a server engine (the `databases` CI job).';
        }
        // The contenders are other processes with the same DB_* environment: they open their own connections.
        $args['serverTables'] = ['cache' => 'mp_race_cache', 'locks' => 'mp_race_cache_locks'];
        Schema::dropIfExists($args['serverTables']['cache']);
        Schema::dropIfExists($args['serverTables']['locks']);
        serverCacheTables($args['serverTables']['cache'], $args['serverTables']['locks']);
    } elseif ($store === 'memcached') {
        $server = getenv('MOBILE_PUSH_TEST_MEMCACHED');
        if (! is_string($server) || $server === '' || ! extension_loaded('memcached')) {
            return 'Set MOBILE_PUSH_TEST_MEMCACHED=host:port (and load ext-memcached) to run the memcached store.';
        }
        [$host, $port] = explode(':', $server) + [1 => '11211'];
        $args['memcached'] = ['host' => $host, 'port' => (int) $port];
        // The server outlives the test: every run gets its own key prefix.
        $args['prefix'] = 'mp-race-'.bin2hex(random_bytes(5)).':';
    } elseif ($store === 'dynamodb') {
        $endpoint = getenv('MOBILE_PUSH_TEST_DYNAMODB');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(DynamoDbClient::class)) {
            return 'Set MOBILE_PUSH_TEST_DYNAMODB=<endpoint> (and install aws/aws-sdk-php) to run the dynamodb store.';
        }
        $args['dynamodb'] = ['endpoint' => $endpoint, 'table' => 'mp_race_'.bin2hex(random_bytes(5))];
        dynamoTable($endpoint, $args['dynamodb']['table']);
    } else {
        $args['cacheDir'] = $dir.'/cache';
        mkdir($args['cacheDir'], 0700);
    }

    return null;
}

/** Stops a redis-server started by prepareStore(). @param  array{process: resource, port: int}|null  $redis */
function stopRedis(?array $redis): void
{
    if ($redis !== null) {
        proc_terminate($redis['process']);
        proc_close($redis['process']);
    }
}
