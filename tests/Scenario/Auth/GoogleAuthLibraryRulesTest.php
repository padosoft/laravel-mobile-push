<?php

declare(strict_types=1);

use Google\Auth\Credentials\ImpersonatedServiceAccountCredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\HttpHandler\HttpClientCache;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\NullTokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Fcm\Auth\GoogleAuthLibraryProvider;
use Padosoft\MobilePush\Tests\Scenario\Auth\QuotaGoogleCredential;
use Padosoft\MobilePush\Tests\Scenario\Auth\RecordingTokenStore;
use Padosoft\MobilePush\Tests\Scenario\Auth\ScriptedGoogleCredential;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * The optional google/auth adapter at its boundaries: how the lifetime is read from what the library
 * returns, when the token is fresh / usable, how refreshes are coordinated, and how a stable, non-secret
 * identity is derived without any network call. No test reaches the network: credentials are scripted,
 * and the one real google/auth credential talks to a Guzzle MockHandler.
 */

/** A credential in the global namespace: its short class name is the whole class name. */
final class GlobalNamespaceGoogleCredential implements FetchAuthTokenInterface
{
    public function fetchAuthToken(?callable $httpHandler = null)
    {
        return ['access_token' => 'ya29.global', 'expires_in' => 3600];
    }

    public function getCacheKey()
    {
        return 'cred-a';
    }

    public function getLastReceivedToken()
    {
        return null;
    }
}

function galKey(): string
{
    static $pem = null;

    return $pem ??= TestKeys::rsa()['pem'];
}

/** @param  array<string, mixed>|Closure(int): mixed  $result */
function galProvider(array|Closure $result, ?FakeClock $clock = null, mixed $store = null, mixed ...$named): GoogleAuthLibraryProvider
{
    $clock ??= new FakeClock;

    return new GoogleAuthLibraryProvider(new ScriptedGoogleCredential($result), $store ?? new InMemoryTokenStore($clock), $clock, ...$named);
}

/** One distinct token per fetch. */
function galTokens(): Closure
{
    return static fn (int $n): array => ['access_token' => 'ya29.fetch-'.$n, 'expires_in' => 3600];
}

it('reads the lifetime from expires_in, else from expires_at, else assumes one hour', function (Closure $result, int $lifetime) {
    $clock = new FakeClock;
    $now = $clock->now()->getTimestamp();
    $store = new RecordingTokenStore($clock);

    $token = galProvider($result($now), $clock, $store)->token();

    expect($token->expiresAt?->getTimestamp())->toBe($now + $lifetime)
        ->and($store->swaps[0]['ttl'])->toBe($lifetime);
})->with([
    'nothing: one hour' => [fn (int $now) => ['access_token' => 'a'], 3600],
    'expires_in 1800' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => 1800], 1800],
    'expires_in "1800" (string)' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => '1800'], 1800],
    'expires_in 1 (boundary)' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => 1], 1],
    'expires_in 0: one hour' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => 0], 3600],
    'expires_in 0.5 (rounds to 0): one hour' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => 0.5], 3600],
    'expires_in "5 minutes" (not numeric): one hour' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => '5 minutes'], 3600],
    'expires_at now + 1234' => [fn (int $now) => ['access_token' => 'a', 'expires_at' => $now + 1234], 1234],
    'expires_at now + 1234.5 (float)' => [fn (int $now) => ['access_token' => 'a', 'expires_at' => $now + 1234.5], 1234],
    'expires_at now (boundary): one hour' => [fn (int $now) => ['access_token' => 'a', 'expires_at' => $now], 3600],
    'expires_at now + 0.5 (rounds to now): one hour' => [fn (int $now) => ['access_token' => 'a', 'expires_at' => $now + 0.5], 3600],
    'expires_at not numeric: one hour' => [fn (int $now) => ['access_token' => 'a', 'expires_at' => '9999999999 later'], 3600],
    'expires_in wins over expires_at' => [fn (int $now) => ['access_token' => 'a', 'expires_in' => 1800, 'expires_at' => $now + 1234], 1800],
]);

it('refuses a library answer without a usable access token', function (mixed $result) {
    expect(fn () => galProvider(static fn () => $result)->token())
        ->toThrow(AuthenticationException::class, 'The google/auth credential returned no access token.');
})->with([
    'no access_token' => [['expires_in' => 3600]],
    'empty access_token' => [['access_token' => '', 'expires_in' => 3600]],
    'access_token not a string' => [['access_token' => 12345, 'expires_in' => 3600]],
    'not an array' => ['ya29.bare-string'],
]);

it('a token is fresh until 300 s before expiry: reused at 3299 s, fetched again at 3300 s', function () {
    $clock = new FakeClock;
    $credential = new ScriptedGoogleCredential(galTokens());
    $provider = new GoogleAuthLibraryProvider($credential, new InMemoryTokenStore($clock), $clock);

    $provider->token();
    $clock->advance(3299);
    expect($provider->token()->value)->toBe('ya29.fetch-1');
    $clock->advance(1);
    expect($provider->token()->value)->toBe('ya29.fetch-2')->and($credential->fetches)->toBe(2);
});

it('refreshes through a 30 s lock that it gives back, and a warm worker does not read the store again', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $provider = galProvider(galTokens(), $clock, $store);

    $provider->token();
    $reads = $store->gets;
    for ($i = 0; $i < 10; $i++) {
        $clock->advance(60);
        $provider->token();
    }

    expect($store->lockRequests)->toBe([['key' => $provider->cacheKey(), 'seconds' => 30]])
        ->and($store->releases)->toBe(1)
        ->and($store->inner->acquireRefreshLock($provider->cacheKey(), 30))->toBeTrue()
        ->and($store->gets)->toBe($reads);
});

it('a fresh worker finding a fresh token in the shared store uses it without taking the lock', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $credential = new ScriptedGoogleCredential(galTokens());
    (new GoogleAuthLibraryProvider($credential, $store, $clock))->token();
    $store->lockRequests = [];

    expect((new GoogleAuthLibraryProvider($credential, $store, $clock))->token()->value)->toBe('ya29.fetch-1')
        ->and($store->lockRequests)->toBe([])
        ->and($credential->fetches)->toBe(1);
});

it('a fresh worker keeps the stored token that is inside the skew window while another worker holds the lock', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $credential = new ScriptedGoogleCredential(galTokens());
    $first = new GoogleAuthLibraryProvider($credential, $store, $clock);
    $first->token();
    $clock->advance(3400);
    $store->acquireRefreshLock($first->cacheKey(), 30);

    expect((new GoogleAuthLibraryProvider($credential, $store, $clock))->token()->value)->toBe('ya29.fetch-1')
        ->and($credential->fetches)->toBe(1);
});

it('after winning the lock it re-reads the store and uses what another worker just stored instead of fetching', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $store->afterLock = static function (string $key) use ($store, $clock): void {
        $store->install($key, new AuthToken('ya29.from-another-worker', $clock->now(), $clock->now()->modify('+3600 seconds')));
    };
    $credential = new ScriptedGoogleCredential(galTokens());

    expect((new GoogleAuthLibraryProvider($credential, $store, $clock))->token()->value)->toBe('ya29.from-another-worker')
        ->and($credential->fetches)->toBe(0)
        ->and($store->releases)->toBe(1);
});

it('while another worker holds the lock it keeps the token until 1 s before expiry, fetches at expiry and leaves their lock alone', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $credential = new ScriptedGoogleCredential(galTokens());
    $provider = new GoogleAuthLibraryProvider($credential, $store, $clock);
    $provider->token();
    $store->acquireRefreshLock($provider->cacheKey(), 100000);

    $clock->advance(3599);
    expect($provider->token()->value)->toBe('ya29.fetch-1');
    $clock->advance(1);
    expect($provider->token()->value)->toBe('ya29.fetch-2')
        ->and($store->acquireRefreshLock($provider->cacheKey(), 30))->toBeFalse();
});

it('with the shared store down a rejection is answered by a new fetch', function () {
    $clock = new FakeClock;
    $provider = galProvider(galTokens(), $clock, new NullTokenStore);
    $rejected = $provider->token();

    expect($provider->refreshAfterRejection($rejected)->value)->toBe('ya29.fetch-2');
});

// --- identity --------------------------------------------------------------------------------

it('derives the documented identity for a credential without an offline principal', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $hash = static fn (string $class, string $quota, string $key): string => substr(hash('sha256', $class."\0".$quota."\0".$key), 0, 16);

    $plain = new ScriptedGoogleCredential;
    $withQuota = new QuotaGoogleCredential('proj-a');

    expect((new GoogleAuthLibraryProvider($plain, $store, $clock))->identity())
        ->toBe('google-auth:ScriptedGoogleCredential:'.$hash(ScriptedGoogleCredential::class, '', 'cred-a'))
        ->and((new GoogleAuthLibraryProvider($withQuota, $store, $clock))->identity())
        ->toBe('google-auth:QuotaGoogleCredential:'.$hash(QuotaGoogleCredential::class, 'proj-a', 'cred-a'))
        ->and((new GoogleAuthLibraryProvider(new GlobalNamespaceGoogleCredential, $store, $clock))->identity())
        ->toBe('google-auth:GlobalNamespaceGoogleCredential:'.$hash(GlobalNamespaceGoogleCredential::class, '', 'cred-a'))
        // never asks a credential that is not a service account for its name (on GCE that is a network call)
        ->and($plain->clientNameCalls)->toBe(0);
});

it('separates identities by quota project and by library cache key; unreadable values count as empty', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $id = static fn (FetchAuthTokenInterface $c): string => (new GoogleAuthLibraryProvider($c, $store, $clock))->identity();

    expect($id(new QuotaGoogleCredential('proj-a')))->not->toBe($id(new QuotaGoogleCredential('proj-b')))
        ->and($id(new QuotaGoogleCredential('a', 'bc')))->not->toBe($id(new QuotaGoogleCredential('ab', 'c')))
        ->and($id(new QuotaGoogleCredential(null)))->toBe($id(new QuotaGoogleCredential('')))
        ->and($id(new ScriptedGoogleCredential(cacheKey: 'cred-a')))->not->toBe($id(new ScriptedGoogleCredential(cacheKey: 'cred-b')))
        ->and($id(new ScriptedGoogleCredential(cacheKeyThrows: true)))->toBe($id(new ScriptedGoogleCredential(cacheKey: '')))
        ->and($id(new ScriptedGoogleCredential(cacheKey: null)))->toBe($id(new ScriptedGoogleCredential(cacheKey: '')));
});

it('uses the email of an impersonated service account, read offline from the impersonation URL', function () {
    $clock = new FakeClock;
    $credential = new ImpersonatedServiceAccountCredentials(GoogleAuthLibraryProvider::FCM_SCOPE, [
        'service_account_impersonation_url' => 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/target@p.iam.gserviceaccount.com:generateAccessToken',
        'source_credentials' => ['type' => 'service_account', 'client_email' => 'source@p.iam.gserviceaccount.com', 'private_key' => galKey()],
    ]);

    expect((new GoogleAuthLibraryProvider($credential, new InMemoryTokenStore($clock), $clock))->identity())
        ->toBe('google-auth:ImpersonatedServiceAccountCredentials:target@p.iam.gserviceaccount.com');
});

it('falls back to the hashed identity when a service account has an empty email', function () {
    $clock = new FakeClock;
    $credential = new ServiceAccountCredentials(GoogleAuthLibraryProvider::FCM_SCOPE, ['client_email' => '', 'private_key' => galKey()]);

    expect((new GoogleAuthLibraryProvider($credential, new InMemoryTokenStore($clock), $clock))->identity())
        ->toMatch('/^google-auth:ServiceAccountCredentials:[0-9a-f]{16}$/');
});

it('a dump shows the identity and cache key of the provider and never the token', function () {
    $clock = new FakeClock;
    $provider = galProvider(['access_token' => 'ya29.dump-me', 'expires_in' => 3600], $clock);
    $provider->token();

    expect($provider->__debugInfo())->toBe(['identity' => $provider->identity(), 'cacheKey' => $provider->cacheKey(), 'token' => '[redacted]'])
        ->and(print_r($provider, true))->not->toContain('ya29.dump-me');
});

// --- Application Default Credentials ------------------------------------------------------------

/**
 * Runs $test with GOOGLE_APPLICATION_CREDENTIALS pointing at $path and google/auth's HTTP client replaced
 * by a MockHandler, then restores both.
 *
 * @param  list<GuzzleResponse>  $answers
 */
function galWithAdc(?string $path, array $answers, Closure $test): void
{
    $previousEnv = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    $previousClient = HttpClientCache::getHttpClient();
    putenv('GOOGLE_APPLICATION_CREDENTIALS='.($path ?? ''));
    HttpClientCache::setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($answers))]));
    try {
        $test();
    } finally {
        putenv($previousEnv === false ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'GOOGLE_APPLICATION_CREDENTIALS='.$previousEnv);
        HttpClientCache::setHttpClient($previousClient);
    }
}

function galAdcFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'adc');
    file_put_contents($path, (string) json_encode([
        'type' => 'service_account',
        'client_email' => 'adc@p.iam.gserviceaccount.com',
        'private_key' => galKey(),
        'private_key_id' => 'adc-kid',
        'client_id' => '1',
        'project_id' => 'p',
    ]));

    return $path;
}

it('builds a provider from Application Default Credentials with a 300 s skew', function (int $secondsLeft, string $expected) {
    $path = galAdcFile();
    $ok = new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.adc-fetched","expires_in":3600,"token_type":"Bearer"}');

    galWithAdc($path, [$ok], function () use ($secondsLeft, $expected) {
        $clock = new FakeClock;
        $store = new InMemoryTokenStore($clock);
        $provider = GoogleAuthLibraryProvider::applicationDefault($store, $clock);
        $store->compareAndSwap($provider->cacheKey(), null, new AuthToken('ya29.cached', $clock->now(), $clock->now()->modify(sprintf('+%d seconds', $secondsLeft))), 3600);

        expect($provider->identity())->toBe('google-auth:ServiceAccountCredentials:adc@p.iam.gserviceaccount.com')
            ->and($provider->token()->value)->toBe($expected);
    });
    unlink($path);
})->with([
    '301 s before expiry: still fresh' => [301, 'ya29.cached'],
    '300 s before expiry: refreshed' => [300, 'ya29.adc-fetched'],
]);

it('reports missing Application Default Credentials as a configuration error', function () {
    galWithAdc('/nonexistent/adc-'.bin2hex(random_bytes(4)).'.json', [], function () {
        $clock = new FakeClock;
        expect(fn () => GoogleAuthLibraryProvider::applicationDefault(new InMemoryTokenStore($clock), $clock))
            ->toThrow(ConfigurationException::class, 'No Application Default Credentials were found');
    });
});
