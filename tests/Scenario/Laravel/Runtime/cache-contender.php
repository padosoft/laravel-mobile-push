<?php

declare(strict_types=1);

/*
 * One contender process for CacheCoordinationTest (started with proc_open, never by Pest itself).
 *
 * It boots a Testbench application with the package, points `mobile-push.cache.store` at the shared store named in
 * its argument, waits for the common start instant (so every contender hits the store at the same moment), then:
 *   1. asks an ApnsTokenProvider (real CacheTokenStore) for the provider token, counting the signatures it makes;
 *   2. takes permits one at a time from a RateLimiter (real CacheGuardStore) with a frozen clock.
 * It prints one JSON line: the number of signatures, a hash of the token it ended up using (never the token) and the
 * permits granted (and how many takes gave up because the guard state was too contended).
 *
 * Argument: JSON {store, redisPort?, sqlitePath?, serverTables?, cacheDir?, memcached?, dynamodb?, prefix?, keyPath,
 * appKey, startAt, now, takes, rate}
 */

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;
use Padosoft\MobilePush\Tests\Support\FakeClock;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/** @var array{store: string, redisPort?: int, sqlitePath?: string, serverTables?: array{cache: string, locks: string}, cacheDir?: string, memcached?: array{host: string, port: int}, dynamodb?: array{endpoint: string, table: string}, prefix?: string, keyPath: string, appKey: string, startAt: float, now: string, takes: int, rate: int} $args */
$args = json_decode($argv[1] ?? '{}', true, 8, JSON_THROW_ON_ERROR);

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

$signer = new class(new OpenSslEs256Signer(PrivateKey::fromPem((string) file_get_contents($args['keyPath'])), 'KEY1111111')) implements Signer
{
    public int $signatures = 0;

    public function __construct(private readonly Signer $inner) {}

    public function sign(string $signingInput): string
    {
        $this->signatures++;

        return $this->inner->sign($signingInput);
    }

    public function algorithm(): string
    {
        return $this->inner->algorithm();
    }

    public function keyId(): ?string
    {
        return $this->inner->keyId();
    }
};

$clock = new FakeClock($args['now']);
$provider = new ApnsTokenProvider($signer, 'TEAM123456', $app->make(TokenStore::class), $clock);
$limiter = new RateLimiter($app->make(GuardStore::class), $clock, $args['rate'], 0.0, $args['rate']);

while (microtime(true) < $args['startAt']) {
    usleep(500);
}

$token = $provider->token();
$granted = 0;
$contended = 0;
for ($i = 0; $i < $args['takes']; $i++) {
    try {
        $granted += $limiter->acquire('apns|team:TEAM123456', 1, TrafficClass::Transactional);
    } catch (RuntimeException) {
        $contended++; // StateCell gave up after its compare-and-set attempts: nothing was granted
    }
}

echo json_encode(['signatures' => $signer->signatures, 'token' => hash('sha256', $token->value), 'granted' => $granted, 'contended' => $contended]), "\n";
