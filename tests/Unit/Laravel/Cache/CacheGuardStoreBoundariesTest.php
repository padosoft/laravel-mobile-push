<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\NoLockStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\RecordingStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

// The doubles share the file of CacheKit: loading it makes them available.
class_exists(CacheKit::class);

/** A store whose reads fail while `$down` is true, counting every read it is asked for. */
function flakyBackend(): ArrayStore
{
    return new class extends ArrayStore
    {
        public bool $down = true;

        public int $reads = 0;

        public function get($key)
        {
            $this->reads++;
            if ($this->down) {
                throw new RuntimeException('Connection refused');
            }

            return parent::get($key);
        }
    };
}

it('without lock support a compare-and-set is refused, the store reports itself unavailable and says why once', function () {
    $logger = new CollectingLogger;
    $backend = new NoLockStore;
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health($logger), 'mobile-push', new FakeClock);

    expect($store->compareAndSet('k', null, 'v', 30))->toBeFalse()
        ->and($store->compareAndSet('k', null, 'v', 30))->toBeFalse()
        ->and($store->available())->toBeFalse()
        ->and($backend->inner->get('mobile-push:guard:'.hash('sha256', 'k')))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toBe(['operation' => 'guard.compareAndSet', 'cause' => 'cache store without lock support']);
});

it('keeps entries and locks under the configured prefix with a hashed key', function () {
    $backend = new RecordingStore;
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'acme', new FakeClock);
    $hash = hash('sha256', 'apns|team:TEAM123456');

    expect($store->compareAndSet('apns|team:TEAM123456', null, 'v', 30))->toBeTrue()
        ->and($backend->lockCalls)->toBe([['acme:lock:guard:'.$hash, 5]])
        ->and($backend->puts)->toHaveCount(1)
        ->and($backend->puts[0][0])->toBe('acme:guard:'.$hash);

    $store->put('other', 'w', 30);
    expect($backend->puts[1][0])->toBe('acme:guard:'.hash('sha256', 'other'));
});

it('stores for at least one second whatever TTL the caller computed', function (int $ttl) {
    $backend = new RecordingStore;
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock);

    $store->put('a', 'v', $ttl);
    expect($store->compareAndSet('b', null, 'v', $ttl))->toBeTrue()
        ->and($backend->puts)->toHaveCount(2)
        ->and($backend->puts[0][2])->toBe(1)
        ->and($backend->puts[1][2])->toBe(1)
        ->and($store->get('a'))->toBe('v')
        ->and($store->get('b'))->toBe('v');
})->with([0, -10]);

it('a put after an outage makes the store available again', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $backend = flakyBackend();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', $clock);

    $store->get('k');
    expect($store->available())->toBeFalse();

    $backend->down = false;
    $clock->advance(CacheGuardStore::RETRY_AFTER_SECONDS);
    $store->put('k', 'v', 30);

    expect($store->available())->toBeTrue()
        ->and($store->get('k'))->toBe('v');
});

it('leaves a failed backend alone for 4 seconds but tries again at exactly the 5th', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $backend = flakyBackend();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', $clock);

    $store->get('k');
    expect($backend->reads)->toBe(1);

    $clock->advance(CacheGuardStore::RETRY_AFTER_SECONDS - 1);
    $store->get('k');
    expect($backend->reads)->toBe(1);

    $clock->advance(1);
    $store->get('k');
    expect($backend->reads)->toBe(2);
});

it('a put into a resting store is dropped without touching the backend', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $backend = flakyBackend();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', $clock);
    $store->get('k');
    $backend->down = false;

    $store->put('k', 'v', 30);
    expect($store->available())->toBeFalse()
        ->and($backend->get('mobile-push:guard:'.hash('sha256', 'k')))->toBeNull();
});

it('without a clock a failed backend is retried on every call', function () {
    $backend = flakyBackend();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health());

    $store->get('k');
    $store->get('k');
    $store->get('k');

    expect($backend->reads)->toBe(3)->and($store->available())->toBeFalse();
});

it('reports which operation found the backend down, by name and exception class, never by message', function () {
    $logger = new CollectingLogger;
    $backend = new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('Connection refused: redis://:hunter2@cache.internal');
        }
    };
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health($logger), 'mobile-push', new FakeClock);

    $store->put('k', 'v', 30);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toBe(['operation' => 'guard.put', 'cause' => RuntimeException::class])
        ->and($logger->text())->not->toContain('hunter2');
});

it('a release that keeps failing is reported but does not change the outcome', function () {
    $logger = new CollectingLogger;
    $backend = new RecordingStore;
    $backend->releaseError = new RuntimeException('Connection refused');
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health($logger), 'mobile-push', new FakeClock);

    expect($store->compareAndSet('k', null, 'v', 30))->toBeTrue()
        ->and($store->available())->toBeTrue()
        ->and($store->get('k'))->toBe('v')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'])->toBe('guard.release');
});

it('reads numbers that a driver hands back as numbers, and nothing else that is not a string', function (mixed $raw, ?string $expected) {
    $backend = new RecordingStore;
    $backend->raw['mobile-push:guard:'.hash('sha256', 'k')] = $raw;
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock);

    expect($store->get('k'))->toBe($expected)->and($store->available())->toBeTrue();
})->with([
    'string' => ['{"a":1}', '{"a":1}'],
    'int' => [42, '42'],
    'float' => [1.5, '1.5'],
    'array' => [['a'], null],
    'true' => [true, null],
    'false' => [false, null],
]);

it('a compare-and-set against a number a driver returned as a number matches its string form', function () {
    $backend = new RecordingStore;
    $backend->raw['mobile-push:guard:'.hash('sha256', 'k')] = 42;
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock);

    expect($store->compareAndSet('k', '42', '43', 30))->toBeTrue()
        ->and($backend->puts[0][1])->toBe('43');
});
