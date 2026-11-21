<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\NullTokenStore;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Tests\Scenario\Auth\CheapEs256Signer;
use Padosoft\MobilePush\Tests\Scenario\Auth\RecordingTokenStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * Apple's provider-token rules at their exact boundaries: refresh-ahead at refreshAfterSeconds (2700 s by
 * default, minus up to 300 s of jitter), never serve a token at maxAgeSeconds (3300 s by default), never
 * mint twice within 1200 s (TooManyProviderTokenUpdates). Mints are counted by the signer.
 */

/** $jitter null = the constructor default (300 s). */
function apnsRulesProvider(CheapEs256Signer $signer, TokenStore $store, FakeClock $clock, ?int $jitter = 0): ApnsTokenProvider
{
    return $jitter === null
        ? new ApnsTokenProvider($signer, 'TEAM123456', $store, $clock)
        : new ApnsTokenProvider($signer, 'TEAM123456', $store, $clock, jitterSeconds: $jitter);
}

function apnsSeed(TokenStore $store, string $key, FakeClock $clock, string $value = 'seed-token'): AuthToken
{
    $token = new AuthToken($value, $clock->now(), $clock->now()->modify('+3300 seconds'));
    $store->compareAndSwap($key, null, $token, 3300);

    return $token;
}

it('refreshes ahead at exactly 2700 s with no jitter, serving the same token from memory one second before', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $provider = apnsRulesProvider($signer, $store, $clock);

    $first = $provider->token();
    $clock->advance(2699);
    expect($provider->token()->value)->toBe($first->value)->and($signer->signatures)->toBe(1);

    $clock->advance(1);
    $second = $provider->token();
    expect($second->value)->not->toBe($first->value)
        ->and($signer->signatures)->toBe(2)
        ->and($second->issuedAt->getTimestamp())->toBe($clock->now()->getTimestamp())
        // the refresh went through the lock, asked for 30 s, and gave it back
        ->and($store->lockRequests)->toBe([['key' => $provider->cacheKey(), 'seconds' => 30]])
        ->and($store->releases)->toBe(1)
        ->and($store->inner->acquireRefreshLock($provider->cacheKey(), 30))->toBeTrue()
        // and compare-and-swapped over the token it replaced, for the maximum age
        ->and(end($store->swaps))->toBe(['key' => $provider->cacheKey(), 'expected' => $first->generation(), 'ttl' => 3300])
        ->and($store->inner->get($provider->cacheKey())?->value)->toBe($second->value);
});

it('a warm worker serves from memory without reading the shared store until the refresh point', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $provider = apnsRulesProvider(new CheapEs256Signer, $store, $clock);

    $provider->token();
    for ($i = 0; $i < 10; $i++) {
        $clock->advance(60);
        $provider->token();
    }

    expect($store->gets)->toBe(1);
});

it('a fresh worker finding a young token in the shared store uses it without taking the refresh lock; at 2700 s it refreshes', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $first = apnsRulesProvider($signer, $store, $clock)->token();

    $clock->advance(100);
    expect(apnsRulesProvider($signer, $store, $clock)->token()->value)->toBe($first->value)
        ->and($store->lockRequests)->toBe([])
        ->and($signer->signatures)->toBe(1);

    $clock->advance(2600 - 1);
    expect(apnsRulesProvider($signer, $store, $clock)->token()->value)->toBe($first->value)
        ->and($signer->signatures)->toBe(1);

    $clock->advance(1);   // age 2700
    expect(apnsRulesProvider($signer, $store, $clock)->token()->value)->not->toBe($first->value)
        ->and($signer->signatures)->toBe(2);
});

it('never serves a token at the 3300 s maximum age, even while another worker holds the refresh lock; at 3299 s it keeps it', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $store->extraTtl = 600;   // the backend keeps the entry past the token's own age limit
    $provider = apnsRulesProvider($signer, $store, $clock);
    $first = $provider->token();
    $store->denyLocks = true;

    $clock->advance(3299);
    expect($provider->token()->value)->toBe($first->value)->and($signer->signatures)->toBe(1);

    $clock->advance(1);
    $second = $provider->token();
    expect($second->value)->not->toBe($first->value)
        ->and($signer->signatures)->toBe(2)
        ->and($first->expiresAt?->getTimestamp() - $first->issuedAt->getTimestamp())->toBe(3300)
        ->and($store->swaps[0]['ttl'])->toBe(3300);
});

it('with the shared store down a worker still refreshes ahead at 2700 s, not only at the maximum age', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $provider = apnsRulesProvider($signer, new NullTokenStore, $clock);

    $first = $provider->token();
    $clock->advance(2699);
    expect($provider->token()->value)->toBe($first->value);
    $clock->advance(1);
    expect($provider->token()->value)->not->toBe($first->value)->and($signer->signatures)->toBe(2);
});

it('after winning the refresh lock it re-reads the store and adopts what another worker just installed instead of signing', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $provider = apnsRulesProvider($signer, $store, $clock);
    $provider->token();
    $clock->advance(2700);

    // The other worker's token is 1400 s old: past the 1200 s floor, not yet due for refresh.
    $theirs = new AuthToken('installed-by-another-worker', $clock->now()->modify('-1400 seconds'));
    $store->afterLock = static function (string $key) use ($store, $theirs): void {
        $store->install($key, $theirs);
    };

    expect($provider->token()->value)->toBe('installed-by-another-worker')
        ->and($signer->signatures)->toBe(1)
        ->and($store->releases)->toBe(1);
});

it('a token adopted from the shared store keeps serving from memory when the store later loses the entry', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new InMemoryTokenStore($clock);
    $first = apnsRulesProvider($signer, $store, $clock)->token();
    $worker = apnsRulesProvider($signer, $store, $clock);

    expect($worker->token()->value)->toBe($first->value);
    $store->forget($worker->cacheKey());
    $clock->advance(60);

    expect($worker->token()->value)->toBe($first->value)->and($signer->signatures)->toBe(1);
});

it('when memory and the shared store disagree, the newer token wins', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $provider = apnsRulesProvider($signer, $store, $clock);
    $provider->token();

    $clock->advance(1500);
    $store->install($provider->cacheKey(), new AuthToken('newer-in-store', $clock->now()));
    $clock->advance(1200);   // memory: 2700 s old (due); store: 1200 s old
    $store->denyLocks = true;

    expect($provider->token()->value)->toBe('newer-in-store')->and($signer->signatures)->toBe(1);
});

it('when memory and the shared store hold tokens issued in the same second, the shared one wins', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $store->extraTtl = 600;
    $provider = apnsRulesProvider($signer, $store, $clock);
    $provider->token();
    $store->install($provider->cacheKey(), new AuthToken('same-second-in-store', $clock->now()));

    $clock->advance(2700);
    $store->denyLocks = true;

    expect($provider->token()->value)->toBe('same-second-in-store')->and($signer->signatures)->toBe(1);
});

it('after a rejection it keeps the token until it is 1200 s old: at 1199 s the same token, at 1200 s a new one', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $provider = apnsRulesProvider($signer, new InMemoryTokenStore($clock), $clock);
    $token = $provider->token();

    $clock->advance(1199);
    expect($provider->refreshAfterRejection($token)->value)->toBe($token->value)->and($signer->signatures)->toBe(1);

    $clock->advance(1);
    expect($provider->refreshAfterRejection($token)->value)->not->toBe($token->value)->and($signer->signatures)->toBe(2);
});

it('a cold worker asked to replace a token it never saw mints one', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $provider = apnsRulesProvider($signer, new InMemoryTokenStore($clock), $clock);

    $fresh = $provider->refreshAfterRejection(new AuthToken('rejected-elsewhere', $clock->now()->modify('-5000 seconds')));

    expect($fresh->value)->not->toBe('rejected-elsewhere')->and($signer->signatures)->toBe(1);
});

it('after a rejection it adopts a different token already in the store, even one older than 1200 s, without signing', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $store = new RecordingTokenStore($clock);
    $rejected = apnsRulesProvider($signer, $store, $clock)->token();
    $store->install(apnsRulesProvider($signer, $store, $clock)->cacheKey(), new AuthToken('other-worker-token', $clock->now()));
    $clock->advance(1300);

    $worker = apnsRulesProvider($signer, $store, $clock);

    expect($worker->refreshAfterRejection($rejected)->value)->toBe('other-worker-token')
        ->and($signer->signatures)->toBe(1);
});

it('with the shared store down, a rejected token 1200 s old is replaced locally', function () {
    $clock = new FakeClock;
    $signer = new CheapEs256Signer;
    $provider = apnsRulesProvider($signer, new NullTokenStore, $clock);
    $token = $provider->token();
    $clock->advance(1200);

    expect($provider->refreshAfterRejection($token)->value)->not->toBe($token->value)->and($signer->signatures)->toBe(2);
});

it('validates its configuration at the boundaries', function (Closure $make, bool $valid) {
    $clock = new FakeClock;
    $build = static fn () => $make(new InMemoryTokenStore($clock), $clock);

    if ($valid) {
        expect($build())->toBeInstanceOf(ApnsTokenProvider::class);
    } else {
        expect($build)->toThrow(ConfigurationException::class);
    }
})->with([
    'empty team id' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, '', $s, $c), false],
    'no key id' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer(null), 'TEAM123456', $s, $c), false],
    'empty key id' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer(''), 'TEAM123456', $s, $c), false],
    'RS256 signer' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer('K', 'RS256'), 'TEAM123456', $s, $c), false],
    'maxAge 3600 (Apple limit)' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, maxAgeSeconds: 3600), true],
    'maxAge 3601' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, maxAgeSeconds: 3601), false],
    'maxAge 1201 with refresh 1200' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, refreshAfterSeconds: 1200, maxAgeSeconds: 1201), true],
    'maxAge 1200' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, refreshAfterSeconds: 1200, maxAgeSeconds: 1200), false],
    'refresh 1200 (floor)' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, refreshAfterSeconds: 1200), true],
    'refresh 1199' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, refreshAfterSeconds: 1199), false],
    'refresh 3299 below maxAge 3300' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, refreshAfterSeconds: 3299), true],
    'refresh equal to maxAge' => [fn ($s, $c) => new ApnsTokenProvider(new CheapEs256Signer, 'T', $s, $c, refreshAfterSeconds: 3300), false],
]);

/**
 * Builds $count providers, each with its own store holding a token issued at t0, and returns for each the
 * first age (among $ages, ascending) at which it re-minted, or null.
 *
 * @param  list<int>  $ages
 * @return list<?int>
 */
function apnsRefreshAges(int $count, ?int $jitter, array $ages): array
{
    $result = [];
    for ($i = 0; $i < $count; $i++) {
        $clock = new FakeClock;
        $store = new InMemoryTokenStore($clock);
        $signer = new CheapEs256Signer;
        $provider = apnsRulesProvider($signer, $store, $clock, $jitter);
        $t0 = $clock->now();
        apnsSeed($store, $provider->cacheKey(), $clock);
        $at = null;
        foreach ($ages as $age) {
            $clock->set($t0->modify(sprintf('+%d seconds', $age)));
            $provider->token();
            if ($signer->signatures > 0) {
                $at = $age;
                break;
            }
        }
        $result[] = $at;
    }

    return $result;
}

it('a negative jitter means no jitter: refresh at exactly 2700 s', function () {
    expect(apnsRefreshAges(3, -1, [2699, 2700]))->toBe([2700, 2700, 2700]);
});

it('a jitter of 1 s spreads the refresh point over 2699 and 2700 s', function () {
    $ages = apnsRefreshAges(64, 1, [2698, 2699, 2700]);

    expect(array_values(array_unique($ages)))->toEqualCanonicalizing([2699, 2700]);   // 2^-63 chance of a false failure
});

it('the default 300 s jitter puts every refresh between 2400 and 2700 s', function () {
    $ages = apnsRefreshAges(6000, null, [2399, 2400, 2700]);

    expect(in_array(2399, $ages, true))->toBeFalse()            // never before 2400 s
        ->and(in_array(null, $ages, true))->toBeFalse()         // always by 2700 s
        ->and(in_array(2400, $ages, true))->toBeTrue();         // 2400 s is reachable (miss chance ~2e-9)
});

it('a dump shows what identifies the provider and never the token', function () {
    $clock = new FakeClock;
    $provider = apnsRulesProvider(new CheapEs256Signer, new InMemoryTokenStore($clock), $clock);
    $value = $provider->token()->value;

    expect($provider->__debugInfo())->toBe(['identity' => 'TEAM123456:KEYID12345', 'cacheKey' => $provider->cacheKey(), 'token' => '[redacted]'])
        ->and(print_r($provider, true))->not->toContain($value);
});
