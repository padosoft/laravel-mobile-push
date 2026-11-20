<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Laravel\Cache\CacheTokenStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DownStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

afterEach(fn () => Carbon::setTestNow());

function bearer(string $value = 'eyJhbGciOiJFUzI1NiJ9.payload-part-1234.signature-part-5678'): AuthToken
{
    return new AuthToken($value, new DateTimeImmutable('2026-11-20T10:00:00.250000Z'), new DateTimeImmutable('2026-11-20T10:55:00Z'));
}

it('round-trips a token with its timestamps and keeps the bearer value out of the raw cache entry', function () {
    $repo = CacheKit::arrayRepository();
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health());
    $token = bearer();

    expect($store->compareAndSwap('k', null, $token, 3300)->value)->toBe($token->value);
    $read = $store->get('k');

    expect($read?->value)->toBe($token->value)
        ->and($read?->issuedAt->format('U.u'))->toBe($token->issuedAt->format('U.u'))
        ->and($read?->expiresAt?->getTimestamp())->toBe($token->expiresAt?->getTimestamp())
        ->and(CacheKit::dump($repo))->not->toContain('payload-part-1234')->not->toContain($token->value);
});

it('expires with the TTL and compare-and-swap loses to a different generation', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20T10:00:00Z'));
    $store = new CacheTokenStore(CacheKit::arrayRepository(), CacheKit::cipher(), CacheKit::health());
    $first = bearer('first-token-value');
    $second = bearer('second-token-value');

    $store->compareAndSwap('k', null, $first, 60);
    expect($store->compareAndSwap('k', null, $second, 60)->value)->toBe('first-token-value')
        ->and($store->compareAndSwap('k', $first->generation(), $second, 60)->value)->toBe('second-token-value');

    Carbon::setTestNow(Carbon::parse('2026-11-20T10:01:01Z'));
    expect($store->get('k'))->toBeNull();
});

it('a value copied under another cache key reads as a miss', function () {
    $repo = CacheKit::arrayRepository();
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health());
    $store->compareAndSwap('tenant-a', null, bearer(), 600);

    $raw = $repo->get('mobile-push:token:'.hash('sha256', 'tenant-a'));
    $repo->put('mobile-push:token:'.hash('sha256', 'tenant-b'), $raw, 600);

    expect($store->get('tenant-b'))->toBeNull()->and($store->get('tenant-a'))->not->toBeNull();
});

it('hands the refresh lock to one holder at a time and forgets entries', function () {
    $repo = CacheKit::arrayRepository();
    $a = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health());
    $b = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health());

    expect($a->acquireRefreshLock('k', 30))->toBeTrue()
        ->and($b->acquireRefreshLock('k', 30))->toBeFalse();
    $a->releaseRefreshLock('k');
    expect($b->acquireRefreshLock('k', 30))->toBeTrue();

    $b->compareAndSwap('k', null, bearer(), 60);
    $b->forget('k');
    expect($b->get('k'))->toBeNull();
});

it('uses the configured prefix on every key', function () {
    $repo = CacheKit::arrayRepository();
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health(), 'acme-push');
    $store->compareAndSwap('k', null, bearer(), 60);
    $store->acquireRefreshLock('k', 30);

    $keys = array_keys(unserialize(CacheKit::dump($repo)));
    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect($key)->toStartWith('acme-push:');
    }
});

it('reports a broken backend at most once a minute', function () {
    $clock = new FakeClock;
    $logger = new CollectingLogger;
    $store = new CacheTokenStore(
        new Repository(new DownStore),
        CacheKit::cipher(),
        CacheKit::health($logger, $clock),
    );

    for ($i = 0; $i < 10; $i++) {
        expect($store->get('k'))->toBeNull();
    }
    expect($logger->records)->toHaveCount(1);

    $clock->advance(61);
    $store->get('k');
    expect($logger->records)->toHaveCount(2)
        ->and($logger->text())->not->toContain('hunter2');
});
