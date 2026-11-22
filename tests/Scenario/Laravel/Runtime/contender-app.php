<?php

declare(strict_types=1);

/*
 * Boots the Testbench application of a contender process (shared by cache-contender.php and policy-contender.php):
 * the package, and `mobile-push.cache.store` pointed at the shared store named in the arguments.
 */

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;

/**
 * @param  array<string, mixed>  $args
 */
function contenderApp(array $args): Application
{
    $app = Testbench::create(null, null, ['extra' => ['providers' => [MobilePushServiceProvider::class]]]);
    // Every store, connection and package singleton below is resolved lazily, after this configuration.
    (static function (Application $app) use ($args): void {
        $config = $app->make('config');
        $config->set('app.key', $args['appKey']);
        $config->set('database.redis.client', 'phpredis');
        foreach (['default', 'cache'] as $connection) {
            $config->set("database.redis.{$connection}", ['host' => '127.0.0.1', 'port' => $args['redisPort'] ?? 6379, 'database' => 0]);
        }
        if (isset($args['sqlitePath'])) {
            $config->set('database.connections.shared', ['driver' => 'sqlite', 'database' => $args['sqlitePath'], 'prefix' => '', 'busy_timeout' => 10_000, 'journal_mode' => 'wal']);
        }
        $config->set('cache.stores.shared-redis', ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default']);
        $config->set('cache.stores.shared-database', ['driver' => 'database', 'connection' => 'shared', 'table' => 'cache', 'lock_connection' => 'shared', 'lock_table' => 'cache_locks']);
        if (isset($args['serverTables'])) {
            // The default connection: the server engine named by the DB_* environment this process inherited.
            $config->set('cache.stores.shared-database-server', ['driver' => 'database', 'connection' => null, 'table' => $args['serverTables']['cache'], 'lock_connection' => null, 'lock_table' => $args['serverTables']['locks']]);
        }
        $config->set('cache.stores.shared-file', ['driver' => 'file', 'path' => $args['cacheDir'] ?? sys_get_temp_dir(), 'lock_path' => $args['cacheDir'] ?? sys_get_temp_dir()]);
        if (isset($args['memcached'])) {
            $config->set('cache.stores.shared-memcached', ['driver' => 'memcached', 'servers' => [['host' => $args['memcached']['host'], 'port' => $args['memcached']['port'], 'weight' => 100]]]);
        }
        if (isset($args['dynamodb'])) {
            $config->set('cache.stores.shared-dynamodb', [
                'driver' => 'dynamodb', 'key' => 'local', 'secret' => 'local', 'region' => 'us-east-1',
                'table' => $args['dynamodb']['table'], 'endpoint' => $args['dynamodb']['endpoint'],
            ]);
        }
        if (isset($args['prefix'])) {
            $config->set('cache.prefix', $args['prefix']);
        }
        $config->set('mobile-push.cache.store', 'shared-'.$args['store']);
        // The redis manager copies its config when it is built (possibly during boot): rebuild it with ours.
        $app->forgetInstance('redis');
        $app->forgetInstance('redis.connection');
    })($app);

    return $app;
}
