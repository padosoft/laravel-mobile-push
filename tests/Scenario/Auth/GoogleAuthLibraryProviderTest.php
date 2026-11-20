<?php

declare(strict_types=1);

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\FetchAuthTokenInterface;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\NullTokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Fcm\Auth\GoogleAuthLibraryProvider;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/** A fake google/auth credential: counts fetches and mints a distinct token each time. */
function fakeGoogleCredential(string $cacheKey = 'cred-a', ?Closure $onFetch = null): FetchAuthTokenInterface
{
    return new class($cacheKey, $onFetch) implements FetchAuthTokenInterface
    {
        public int $fetches = 0;

        public function __construct(private string $cacheKey, private ?Closure $onFetch) {}

        public function fetchAuthToken(?callable $httpHandler = null)
        {
            $this->fetches++;
            if ($this->onFetch !== null) {
                ($this->onFetch)();
            }

            return ['access_token' => 'ya29.'.$this->cacheKey.'.'.$this->fetches, 'expires_in' => 3600];
        }

        public function getCacheKey()
        {
            return $this->cacheKey;
        }

        public function getLastReceivedToken()
        {
            return null;
        }
    };
}

it('reuses the token until the refresh skew and then refreshes once', function () {
    $clock = new FakeClock;
    $credential = fakeGoogleCredential();
    $provider = new GoogleAuthLibraryProvider($credential, new InMemoryTokenStore($clock), $clock);

    $first = $provider->token();
    $clock->advance(3000);
    expect($provider->token()->generation())->toBe($first->generation())->and($credential->fetches)->toBe(1);

    $clock->advance(301); // inside the 300 s skew window (3301 > 3300)
    $second = $provider->token();
    expect($second->generation())->not->toBe($first->generation())->and($credential->fetches)->toBe(2);
});

it('fifty provider instances sharing one store refresh once', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $credential = fakeGoogleCredential();

    $generations = [];
    for ($i = 0; $i < 50; $i++) {
        $generations[] = (new GoogleAuthLibraryProvider($credential, $store, $clock))->token()->generation();
    }

    expect($credential->fetches)->toBe(1)->and(array_unique($generations))->toHaveCount(1);
});

it('a rejected token triggers exactly one new fetch, even when several callers report it', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $credential = fakeGoogleCredential();
    $a = new GoogleAuthLibraryProvider($credential, $store, $clock);
    $b = new GoogleAuthLibraryProvider($credential, $store, $clock);

    $old = $a->token();
    $b->token();
    $new = $a->refreshAfterRejection($old);
    $adopted = $b->refreshAfterRejection($old);

    expect($credential->fetches)->toBe(2)
        ->and($new->generation())->not->toBe($old->generation())
        ->and($adopted->generation())->toBe($new->generation());
});

it('keeps one token in process when the shared store is unavailable', function () {
    $clock = new FakeClock;
    $credential = fakeGoogleCredential();
    $provider = new GoogleAuthLibraryProvider($credential, new NullTokenStore, $clock);

    $provider->token();
    $provider->token();
    $provider->token();

    expect($credential->fetches)->toBe(1);
});

it('wraps a failing credential without leaking its message or any token', function () {
    $clock = new FakeClock;
    $credential = new class implements FetchAuthTokenInterface
    {
        public function fetchAuthToken(?callable $httpHandler = null)
        {
            throw new RuntimeException('Bearer ya29.SECRET-TOKEN refused: {"private_key":"-----BEGIN PRIVATE KEY-----"}');
        }

        public function getCacheKey()
        {
            return 'x';
        }

        public function getLastReceivedToken()
        {
            return null;
        }
    };
    $provider = new GoogleAuthLibraryProvider($credential, new InMemoryTokenStore($clock), $clock);

    try {
        $provider->token();
        $this->fail('expected AuthenticationException');
    } catch (AuthenticationException $e) {
        expect($e->getMessage())->not->toContain('SECRET')->not->toContain('PRIVATE')->not->toContain('Bearer')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('two different principals never share a cache entry', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $one = fakeGoogleCredential('principal-one');
    $two = fakeGoogleCredential('principal-two');
    $a = new GoogleAuthLibraryProvider($one, $store, $clock);
    $b = new GoogleAuthLibraryProvider($two, $store, $clock);

    expect($a->identity())->not->toBe($b->identity())
        ->and($a->cacheKey())->not->toBe($b->cacheKey())
        ->and($a->token()->generation())->not->toBe($b->token()->generation())
        ->and([$one->fetches, $two->fetches])->toBe([1, 1])
        ->and($a->identity())->toStartWith('google-auth:')->not->toContain('principal-one');

    // same credential, same identity: stable across instances
    expect((new GoogleAuthLibraryProvider($one, $store, $clock))->identity())->toBe($a->identity());
    // a different binding scope separates caches too
    expect((new GoogleAuthLibraryProvider($one, $store, $clock, 'other-scope'))->cacheKey())->not->toBe($a->cacheKey());
});

it('does not show tokens when dumped', function () {
    $clock = new FakeClock;
    $provider = new GoogleAuthLibraryProvider(fakeGoogleCredential(), new InMemoryTokenStore($clock), $clock);
    $token = $provider->token();

    expect(var_export($provider, true))->not->toContain($token->value)
        ->and(print_r($provider, true))->not->toContain($token->value);
});

it('uses the service-account email as identity when the credential exposes it offline', function () {
    $clock = new FakeClock;
    $key = TestKeys::rsa()['pem'];
    $credential = new ServiceAccountCredentials(
        'https://www.googleapis.com/auth/firebase.messaging',
        ['client_email' => 'push@p.iam.gserviceaccount.com', 'private_key' => $key, 'client_id' => '1'],
    );

    $provider = new GoogleAuthLibraryProvider($credential, new InMemoryTokenStore($clock), $clock);

    expect($provider->identity())->toBe('google-auth:ServiceAccountCredentials:push@p.iam.gserviceaccount.com')
        ->and(var_export($provider, true))->not->toContain('PRIVATE');
});
