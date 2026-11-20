<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\NullTokenStore;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Tests\Scenario\Auth\CountingSigner;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function apnsSigner(string $keyId = 'KEYID12345'): CountingSigner
{
    return new CountingSigner(new OpenSslEs256Signer(PrivateKey::fromPem(TestKeys::ec()['pem']), $keyId));
}

function apnsProvider(CountingSigner $signer, TokenStore $store, FakeClock $clock, string $team = 'TEAM123456', string $scope = 'default', int $jitter = 300): ApnsTokenProvider
{
    return new ApnsTokenProvider($signer, $team, $store, $clock, scope: $scope, jitterSeconds: $jitter);
}

it('a long-lived worker over three hours never uses a token older than 60 minutes nor mints more often than every 20', function () {
    $clock = new FakeClock;
    $signer = apnsSigner();
    $provider = apnsProvider($signer, new InMemoryTokenStore($clock), $clock);

    $seen = [];
    for ($elapsed = 0; $elapsed <= 3 * 3600; $elapsed += 15) {
        $token = $provider->token();
        expect($token->ageSeconds($clock->now()))->toBeLessThan(3600);
        $seen[$token->generation()] = $token->issuedAt->getTimestamp();
        $clock->advance(15);
    }

    $mintTimes = array_values($seen);
    sort($mintTimes);
    foreach (array_slice($mintTimes, 1, null, true) as $i => $at) {
        expect($at - $mintTimes[$i - 1])->toBeGreaterThanOrEqual(1200);
    }
    expect($signer->signatures)->toBe(count($seen))
        ->and($signer->signatures)->toBeGreaterThanOrEqual(4)   // refreshed ahead, not only on expiry
        ->and($signer->signatures)->toBeLessThanOrEqual(5);
});

it('fifty workers hit ExpiredProviderToken at once and exactly one new token is minted', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $signer = apnsSigner();

    $first = apnsProvider($signer, $store, $clock);
    $rejected = $first->token();
    $clock->advance(30 * 60);

    $workers = [];
    for ($i = 0; $i < 50; $i++) {
        $workers[] = apnsProvider($signer, $store, $clock);
    }
    $results = array_map(static fn (ApnsTokenProvider $w) => $w->refreshAfterRejection($rejected)->value, $workers);

    expect($signer->signatures)->toBe(2)
        ->and(array_unique($results))->toHaveCount(1)
        ->and($results[0])->not->toBe($rejected->value)
        ->and($store->get($first->cacheKey())?->value)->toBe($results[0]);
});

it('refuses to mint inside the 20 minute floor even when asked after a rejection', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $signer = apnsSigner();
    $provider = apnsProvider($signer, $store, $clock);

    $token = $provider->token();
    $clock->advance(19 * 60);
    $again = $provider->refreshAfterRejection($token);

    expect($again->value)->toBe($token->value)->and($signer->signatures)->toBe(1);

    $clock->advance(2 * 60);
    expect($provider->refreshAfterRejection($token)->value)->not->toBe($token->value)
        ->and($signer->signatures)->toBe(2);
});

it('refresh-ahead lets a single winner mint while other workers keep the still-valid token', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $signer = apnsSigner();
    $old = apnsProvider($signer, $store, $clock, jitter: 0)->token();
    $clock->advance(2700 + 1);

    // Another process holds the refresh lock and is minting.
    $store->acquireRefreshLock(apnsProvider($signer, $store, $clock)->cacheKey(), 30);
    $loser = apnsProvider($signer, $store, $clock, jitter: 0);

    expect($loser->token()->value)->toBe($old->value)->and($signer->signatures)->toBe(1);
});

it('the cache is down: tokens are still served, minted once per process and reused', function () {
    $clock = new FakeClock;
    $signer = apnsSigner();
    $provider = apnsProvider($signer, new NullTokenStore, $clock);

    $first = $provider->token();
    for ($i = 0; $i < 500; $i++) {
        expect($provider->token()->value)->toBe($first->value);
        $clock->advance(1); // 500 s, far below the refresh threshold
    }
    expect($signer->signatures)->toBe(1);

    for ($elapsed = 0; $elapsed < 3 * 3600; $elapsed += 20) {
        expect($provider->token()->ageSeconds($clock->now()))->toBeLessThan(3600);
        $clock->advance(20);
    }
    expect($signer->signatures)->toBeLessThanOrEqual(5);
});

it('two apps sharing a store never collide', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $a = apnsProvider(apnsSigner('KEYAAAAAAA'), $store, $clock, 'TEAMAAAAAA');
    $b = apnsProvider(apnsSigner('KEYBBBBBBB'), $store, $clock, 'TEAMBBBBBB');
    $sameIdentityOtherScope = apnsProvider(apnsSigner('KEYAAAAAAA'), $store, $clock, 'TEAMAAAAAA', 'tenant-2');

    expect($a->token()->value)->not->toBe($b->token()->value)
        ->and($a->cacheKey())->not->toBe($b->cacheKey())
        ->and($a->cacheKey())->not->toBe($sameIdentityOtherScope->cacheKey())
        ->and($a->identity())->toBe('TEAMAAAAAA:KEYAAAAAAA')
        ->and($a->token()->value)->toBe($store->get($a->cacheKey())?->value)
        ->and($b->token()->value)->toBe($store->get($b->cacheKey())?->value);
});

it('mints a token whose header and claims match what Apple expects', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $provider = apnsProvider(apnsSigner('KEYID12345'), new InMemoryTokenStore($clock), $clock);

    [$h, $c] = explode('.', $provider->token()->value);
    $dec = static fn (string $p): array => json_decode(base64_decode(strtr($p, '-_', '+/')), true);

    expect($dec($h))->toBe(['typ' => 'JWT', 'kid' => 'KEYID12345', 'alg' => 'ES256'])
        ->and($dec($c))->toBe(['iss' => 'TEAM123456', 'iat' => $clock->now()->getTimestamp()]);
});

it('never reveals the token through dumps or serialization', function () {
    $clock = new FakeClock;
    $provider = apnsProvider(apnsSigner(), new InMemoryTokenStore($clock), $clock);
    $value = $provider->token()->value;

    expect(print_r($provider, true))->not->toContain($value)
        ->and(var_export($provider, true))->not->toContain($value)
        ->and(fn () => serialize($provider))->toThrow(LogicException::class);
});
