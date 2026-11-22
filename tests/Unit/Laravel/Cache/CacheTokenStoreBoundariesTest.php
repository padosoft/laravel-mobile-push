<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Laravel\Cache\CacheTokenStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DownStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\NoLockStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\RecordingStore;

// The doubles share the file of CacheKit: loading it makes them available.
class_exists(CacheKit::class);

function fakeBearer(string $value = 'fake-bearer-value-0001'): AuthToken
{
    return new AuthToken($value, new DateTimeImmutable('2026-11-20T10:00:00.250000Z'), new DateTimeImmutable('2026-11-20T10:55:00Z'));
}

function tokenEntryKey(string $cacheKey, string $prefix = 'mobile-push'): string
{
    return $prefix.':token:'.hash('sha256', $cacheKey);
}

it('without lock support it hands back what is stored, else the new token, writes nothing and says why once', function () {
    $logger = new CollectingLogger;
    $backend = new NoLockStore;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health($logger));

    expect($store->compareAndSwap('k', null, fakeBearer('new-token'), 60)->value)->toBe('new-token')
        ->and($backend->inner->get(tokenEntryKey('k')))->toBeNull()
        ->and($store->get('k'))->toBeNull();

    // Something stored by a process that does have locks: the unsupported store returns it instead of the new token.
    $seeder = new CacheTokenStore(new Repository($backend->inner), CacheKit::cipher(), CacheKit::health());
    $seeder->compareAndSwap('k', null, fakeBearer('stored-token'), 60);

    expect($store->compareAndSwap('k', null, fakeBearer('new-token'), 60)->value)->toBe('stored-token')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toBe(['operation' => 'token.compareAndSwap', 'cause' => 'cache store without lock support']);
});

it('when the lock stays contended it writes nothing and returns the stored token, else the new one', function () {
    $backend = new RecordingStore;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health());

    $backend->heldAttempts = PHP_INT_MAX;
    expect($store->compareAndSwap('k', null, fakeBearer('mine'), 60)->value)->toBe('mine')
        ->and($backend->puts)->toBe([]);

    $backend->heldAttempts = 0;
    $store->compareAndSwap('k', null, fakeBearer('winner'), 60);
    $backend->heldAttempts = PHP_INT_MAX;
    $backend->attempts = 0;
    $putsBefore = count($backend->puts);

    expect($store->compareAndSwap('k', null, fakeBearer('mine'), 60)->value)->toBe('winner')
        ->and($backend->attempts)->toBe(20)
        ->and($backend->puts)->toHaveCount($putsBefore);
});

it('keeps the entry, the swap lock and the refresh lock under the prefix with hashed names', function () {
    $backend = new RecordingStore;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health(), 'acme');
    $hash = hash('sha256', 'tenant-a');

    $store->compareAndSwap('tenant-a', null, fakeBearer(), 60);
    expect($store->acquireRefreshLock('tenant-a', 17))->toBeTrue()
        ->and($backend->puts[0][0])->toBe('acme:token:'.$hash)
        ->and($backend->lockCalls)->toBe([
            ['acme:lock:token-cas:'.$hash, 5],
            ['acme:lock:token-refresh:'.$hash, 17],
        ]);

    $store->forget('tenant-a');
    expect($backend->forgotten)->toBe(['acme:token:'.$hash]);
});

it('stores for at least one second whatever TTL the caller computed', function (int $ttl) {
    $backend = new RecordingStore;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health());

    expect($store->compareAndSwap('k', null, fakeBearer(), $ttl)->value)->toBe('fake-bearer-value-0001')
        ->and($backend->puts)->toHaveCount(1)
        ->and($backend->puts[0][2])->toBe(1)
        ->and($store->get('k')?->value)->toBe('fake-bearer-value-0001');
})->with([0, -10]);

it('the refresh lock asks for at least one second', function () {
    $backend = new RecordingStore;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health());

    expect($store->acquireRefreshLock('k', 0))->toBeTrue()
        ->and($backend->lockCalls[0][1])->toBe(1);
});

it('a refresh lock that cannot be taken answers false, whether it is held or the backend is down', function () {
    $logger = new CollectingLogger;
    $held = new RecordingStore;
    $held->heldAttempts = PHP_INT_MAX;
    $down = new CacheTokenStore(new Repository(new DownStore), CacheKit::cipher(), CacheKit::health($logger));

    expect((new CacheTokenStore(new Repository($held), CacheKit::cipher(), CacheKit::health()))->acquireRefreshLock('k', 30))->toBeFalse()
        ->and($down->acquireRefreshLock('k', 30))->toBeFalse()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toBe(['operation' => 'token.acquireRefreshLock', 'cause' => RuntimeException::class])
        ->and($logger->text())->not->toContain('hunter2');
});

it('a refresh lock on a store without lock support is simply not granted', function () {
    $store = new CacheTokenStore(new Repository(new NoLockStore), CacheKit::cipher(), CacheKit::health());

    expect($store->acquireRefreshLock('k', 30))->toBeFalse();
});

it('releasing a refresh lock this instance does not hold does nothing, and releasing twice is harmless', function () {
    $backend = new RecordingStore;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health());

    $store->releaseRefreshLock('never-taken');
    expect($backend->releases)->toBe(0);

    $store->acquireRefreshLock('k', 30);
    $store->releaseRefreshLock('k');
    $store->releaseRefreshLock('k');
    expect($backend->releases)->toBe(1);
});

it('forgetting on a backend that throws is reported and never thrown', function () {
    $logger = new CollectingLogger;
    $backend = new RecordingStore;
    $backend->forgetError = new RuntimeException('Connection refused');
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health($logger));

    $store->forget('k');

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toBe(['operation' => 'token.forget', 'cause' => RuntimeException::class]);
});

it('a release that fails is reported as token.release and does not change what the caller gets', function () {
    $logger = new CollectingLogger;
    $backend = new RecordingStore;
    $backend->releaseError = new RuntimeException('Connection refused');
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health($logger));

    expect($store->compareAndSwap('k', null, fakeBearer('mine'), 60)->value)->toBe('mine')
        ->and($store->get('k')?->value)->toBe('mine')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'])->toBe('token.release');
});

it('a refresh lock whose release fails is reported as token.release', function () {
    $logger = new CollectingLogger;
    $backend = new RecordingStore;
    $backend->releaseError = new RuntimeException('Connection refused');
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health($logger));

    $store->acquireRefreshLock('k', 30);
    $store->releaseRefreshLock('k');

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'])->toBe('token.release');
});

it('a backend error in the middle of a swap returns the new token, reported once', function () {
    $logger = new CollectingLogger;
    $backend = new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('Connection refused');
        }
    };
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health($logger));

    expect($store->compareAndSwap('k', null, fakeBearer('mine'), 60)->value)->toBe('mine')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'])->toBe('token.compareAndSwap');
});

/** Writes `$payload` into the cache exactly as the store would write a token: encrypted and bound to the key. */
function plant(Repository $repo, string $cacheKey, string $payload): void
{
    $repo->put(tokenEntryKey($cacheKey), CacheKit::cipher()->encrypt($payload, $cacheKey, 'token-store'), 600);
}

it('reads an entry that is not a well-formed token as a miss', function (string $payload) {
    $repo = CacheKit::arrayRepository();
    plant($repo, 'k', $payload);
    $logger = new CollectingLogger;
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health($logger));

    expect($store->get('k'))->toBeNull()
        ->and($logger->records)->toBe([])
        ->and($store->compareAndSwap('k', null, fakeBearer('fresh'), 60)->value)->toBe('fresh');
})->with([
    'not an object' => ['"just a string"'],
    'a list' => ['[1,2]'],
    'null' => ['null'],
    'no value' => ['{"i":"1763632800.000000"}'],
    'value is a number' => ['{"v":12345,"i":"1763632800.000000"}'],
    'no issue time' => ['{"v":"t"}'],
    'issue time is a number' => ['{"v":"t","i":1763632800}'],
    'issue time has the wrong format' => ['{"v":"t","i":"yesterday"}'],
]);

it('an entry that decodes but is stale in shape does not block a fresh swap with a different generation', function () {
    $repo = CacheKit::arrayRepository();
    plant($repo, 'k', '{"v":12345,"i":"1763632800.000000"}');
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health());

    $store->compareAndSwap('k', null, fakeBearer('fresh'), 60);

    expect($store->get('k')?->value)->toBe('fresh');
});

it('reads a token without a usable expiry as one that does not expire', function (string $expires) {
    $repo = CacheKit::arrayRepository();
    plant($repo, 'k', '{"v":"bearer-xyz","i":"1763632800.500000"'.$expires.'}');
    $read = (new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health()))->get('k');

    expect($read?->value)->toBe('bearer-xyz')
        ->and($read?->issuedAt->format('U.u'))->toBe('1763632800.500000')
        ->and($read?->expiresAt)->toBeNull();
})->with([
    'absent' => [''],
    'null' => [',"e":null'],
    'a number' => [',"e":1763636400'],
    'wrong format' => [',"e":"soon"'],
]);

it('reads a token with an expiry', function () {
    $repo = CacheKit::arrayRepository();
    plant($repo, 'k', '{"v":"bearer-xyz","i":"1763632800.500000","e":"1763636400.000000"}');

    expect((new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health()))->get('k')?->expiresAt?->getTimestamp())->toBe(1763636400);
});

it('reads anything that is not a non-empty string of ciphertext as a miss without complaining', function (mixed $raw) {
    $logger = new CollectingLogger;
    $backend = new RecordingStore;
    $backend->raw[tokenEntryKey('k')] = $raw;
    $store = new CacheTokenStore(new Repository($backend), CacheKit::cipher(), CacheKit::health($logger));

    expect($store->get('k'))->toBeNull()->and($logger->records)->toBe([]);
})->with(['empty string' => [''], 'int' => [5], 'array' => [['a']], 'bool' => [true]]);

it('reports an undecryptable entry once as token.decode and reads it as a miss', function () {
    $logger = new CollectingLogger;
    $repo = CacheKit::arrayRepository();
    $repo->put(tokenEntryKey('k'), 'not-a-valid-envelope', 600);
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health($logger));

    expect($store->get('k'))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'])->toBe('token.decode');
});

it('round-trips a token that never expires', function () {
    $store = new CacheTokenStore(CacheKit::arrayRepository(), CacheKit::cipher(), CacheKit::health());
    $token = new AuthToken('never-expires-token', new DateTimeImmutable('2026-11-20T10:00:00.125000Z'), null);

    $store->compareAndSwap('k', null, $token, 60);

    expect($store->get('k')?->value)->toBe('never-expires-token')
        ->and($store->get('k')?->expiresAt)->toBeNull();
});

it('tolerates unknown nested fields up to the decoder depth and reads deeper ones as a corrupt entry', function () {
    $logger = new CollectingLogger;
    $repo = CacheKit::arrayRepository();
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health($logger));
    $base = '"v":"bearer-xyz","i":"1763632800.000000"';

    plant($repo, 'ok', '{'.$base.',"x":{"y":{"z":1}}}');
    plant($repo, 'deep', '{'.$base.',"x":{"y":{"z":{"w":1}}}}');

    expect($store->get('ok')?->value)->toBe('bearer-xyz')
        ->and($logger->records)->toBe([])
        ->and($store->get('deep'))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'])->toBe('token.decode');
});
