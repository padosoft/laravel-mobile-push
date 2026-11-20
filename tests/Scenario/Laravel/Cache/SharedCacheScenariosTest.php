<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Guard\AccountKey;
use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Laravel\Cache\CacheTokenStore;
use Padosoft\MobilePush\Tests\Scenario\Auth\CountingSigner;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DownStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function cacheSigner(): CountingSigner
{
    return new CountingSigner(new OpenSslEs256Signer(PrivateKey::fromPem(TestKeys::ec()['pem']), 'KEYID12345'));
}

function cacheProvider(CountingSigner $signer, CacheTokenStore $store, FakeClock $clock, string $scope = 'default'): ApnsTokenProvider
{
    return new ApnsTokenProvider($signer, 'TEAM123456', $store, $clock, scope: $scope, jitterSeconds: 0);
}

it('fifty workers refresh at once on a shared cache and exactly one new token is minted', function () {
    $clock = new FakeClock;
    $store = new CacheTokenStore(CacheKit::arrayRepository(), CacheKit::cipher(), CacheKit::health());
    $signer = cacheSigner();

    $rejected = cacheProvider($signer, $store, $clock)->token();
    $clock->advance(30 * 60);

    $results = [];
    for ($i = 0; $i < 50; $i++) {
        $results[] = cacheProvider($signer, $store, $clock)->refreshAfterRejection($rejected)->value;
    }

    expect($signer->signatures)->toBe(2)
        ->and(array_unique($results))->toHaveCount(1)
        ->and($results[0])->not->toBe($rejected->value);
});

it('Redis is down: providers keep serving locally minted tokens, nothing throws, one warning without secrets', function () {
    $clock = new FakeClock;
    $logger = new CollectingLogger;
    $store = new CacheTokenStore(new Repository(new DownStore), CacheKit::cipher(), CacheKit::health($logger, $clock));
    $signer = cacheSigner();
    $provider = cacheProvider($signer, $store, $clock);

    $first = $provider->token();
    $clock->advance(30);
    $again = $provider->token();
    $afterRejection = $provider->refreshAfterRejection($first);   // inside Apple's 20 minute floor: keep the token

    expect($again->value)->toBe($first->value)
        ->and($afterRejection->value)->toBe($first->value)
        ->and($signer->signatures)->toBe(1)
        ->and($logger->records)->not->toBeEmpty()
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->text())->not->toContain($first->value)->not->toContain('hunter2')->not->toContain('eyJ');
    expect(count($logger->records))->toBe(1);
});

it('two tenants never read each other token', function () {
    $clock = new FakeClock;
    $store = new CacheTokenStore(CacheKit::arrayRepository(), CacheKit::cipher(), CacheKit::health());
    $signerA = cacheSigner();
    $signerB = cacheSigner();

    $a = cacheProvider($signerA, $store, $clock, 'tenant-a');
    $b = cacheProvider($signerB, $store, $clock, 'tenant-b');
    $tokenA = $a->token();
    $tokenB = $b->token();

    expect($a->cacheKey())->not->toBe($b->cacheKey())
        ->and($tokenA->value)->not->toBe($tokenB->value)
        ->and($store->get($a->cacheKey())?->value)->toBe($tokenA->value)
        ->and($store->get($b->cacheKey())?->value)->toBe($tokenB->value)
        ->and($signerA->signatures)->toBe(1)->and($signerB->signatures)->toBe(1);

    $store->forget($a->cacheKey());
    expect($store->get($a->cacheKey()))->toBeNull()->and($store->get($b->cacheKey()))->not->toBeNull();
});

it('the guards keep working on the shared cache', function () {
    $clock = new FakeClock;
    $repo = CacheKit::arrayRepository();
    $storeA = new CacheGuardStore($repo, CacheKit::health());
    $storeB = new CacheGuardStore($repo, CacheKit::health());

    // Rate limiter: two workers with their own instance, one cache.
    $rate = 6000;
    $burst = 100;
    $workerA = new RateLimiter($storeA, $clock, $rate, 0.0, $burst);
    $workerB = new RateLimiter($storeB, $clock, $rate, 0.0, $burst);
    $key = AccountKey::of(Provider::Fcm, 'shop-prod');
    $total = 0;
    for ($s = 0; $s < 60; $s++) {
        foreach ([[$workerA, 700], [$workerB, 900]] as [$worker, $n]) {
            $total += $worker->acquire($key, $n, TrafficClass::Bulk);
        }
        $clock->advance(1);
    }
    expect($total)->toBeLessThanOrEqual($rate + $burst)->and($total)->toBeGreaterThanOrEqual($rate);

    // Auth breaker: fifty failures of generation 0 split across two workers decide the failover exactly once.
    $breakerA = new AuthBreaker($storeA, $clock, 5, 60);
    $breakerB = new AuthBreaker($storeB, $clock, 5, 60);
    $failovers = 0;
    $opens = 0;
    for ($i = 0; $i < 50; $i++) {
        $t = ($i % 2 === 0 ? $breakerA : $breakerB)->recordFailure('apns-key', 0, true);
        $failovers += $t === BreakerTransition::FailedOver ? 1 : 0;
        $opens += in_array($t, [BreakerTransition::Opened, BreakerTransition::Reopened], true) ? 1 : 0;
    }
    expect($failovers)->toBe(1)->and($opens)->toBe(0)
        ->and($breakerA->activeGeneration('apns-key'))->toBe(1)
        ->and($breakerB->activeGeneration('apns-key'))->toBe(1);
});

it('a cache dump leaks nothing', function () {
    $clock = new FakeClock;
    $repo = CacheKit::arrayRepository();
    $store = new CacheTokenStore($repo, CacheKit::cipher(), CacheKit::health());
    $values = [];
    foreach (['tenant-a', 'tenant-b', 'tenant-c'] as $scope) {
        $provider = cacheProvider(cacheSigner(), $store, $clock, $scope);
        $values[] = $provider->token()->value;
        $store->acquireRefreshLock($provider->cacheKey(), 30);
    }

    $dump = CacheKit::dump($repo);
    expect($dump)->not->toBe('');
    foreach ($values as $value) {
        [$header, $payload, $signature] = explode('.', $value);
        expect($dump)->not->toContain($value)->not->toContain($header)->not->toContain($payload)->not->toContain($signature);
    }
    expect($dump)->not->toContain('TEAM123456')->not->toContain('KEYID12345');
});
