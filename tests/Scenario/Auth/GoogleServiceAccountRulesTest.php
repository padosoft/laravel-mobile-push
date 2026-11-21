<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Core\Auth\NullTokenStore;
use Padosoft\MobilePush\Core\Auth\OpenSslRs256Signer;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountCredentials;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\HttpVersion;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Auth\CountingSigner;
use Padosoft\MobilePush\Tests\Scenario\Auth\RecordingTokenStore;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * The Google service-account flow at its boundaries: the access token is fresh until expires_in minus the
 * skew (300 s), usable until expires_in, the refresh is coordinated through the shared store's lock, and
 * every malformed answer of the token endpoint is an AuthenticationException that carries no secret.
 */

/** @return array{pem: string, public: string} one RSA key per process: generating one is slow */
function saRulesKey(): array
{
    static $key = null;

    return $key ??= TestKeys::rsa();
}

/** @param  array<string, mixed>  $overrides */
function saRulesJson(array $overrides = []): string
{
    return (string) json_encode($overrides + [
        'type' => 'service_account',
        'project_id' => 'demo-project',
        'private_key_id' => 'key-id-1',
        'private_key' => saRulesKey()['pem'],
        'client_email' => 'push@demo-project.iam.gserviceaccount.com',
    ]);
}

function saRulesOk(string $token = 'ya29.one', mixed $expiresIn = 3600): Response
{
    return new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => $token, 'expires_in' => $expiresIn]), '1.1');
}

function saRulesProvider(FakeTransport $transport, TokenStore $store, FakeClock $clock, mixed ...$named): ServiceAccountTokenProvider
{
    return new ServiceAccountTokenProvider(ServiceAccountCredentials::fromJson(saRulesJson()), $transport, $store, $clock, ...$named);
}

it('a token is fresh until expires_in minus the 300 s skew: reused at 3299 s, exchanged again at 3300 s', function () {
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(saRulesOk('ya29.one'), saRulesOk('ya29.two'));
    $provider = saRulesProvider($transport, new InMemoryTokenStore($clock), $clock);

    $provider->token();
    $clock->advance(3299);
    expect($provider->token()->value)->toBe('ya29.one')->and($transport->sentCount())->toBe(1);

    $clock->advance(1);
    expect($provider->token()->value)->toBe('ya29.two')->and($transport->sentCount())->toBe(2);
});

it('refreshes through a 30 s lock that it gives back, and a warm worker does not read the store again', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $transport = (new FakeTransport)->push(saRulesOk('ya29.one'));
    $provider = saRulesProvider($transport, $store, $clock);

    $provider->token();
    $readsAfterExchange = $store->gets;
    for ($i = 0; $i < 10; $i++) {
        $clock->advance(60);
        expect($provider->token()->value)->toBe('ya29.one');
    }

    expect($store->lockRequests)->toBe([['key' => $provider->cacheKey(), 'seconds' => 30]])
        ->and($store->releases)->toBe(1)
        ->and($store->inner->acquireRefreshLock($provider->cacheKey(), 30))->toBeTrue()
        ->and($store->swaps)->toBe([['key' => $provider->cacheKey(), 'expected' => null, 'ttl' => 3600]])
        ->and($store->gets)->toBe($readsAfterExchange);
});

it('a fresh worker finding a fresh token in the shared store uses it without taking the lock', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $transport = (new FakeTransport)->push(saRulesOk('ya29.one'));
    saRulesProvider($transport, $store, $clock)->token();
    $store->lockRequests = [];
    $clock->advance(600);

    expect(saRulesProvider($transport, $store, $clock)->token()->value)->toBe('ya29.one')
        ->and($store->lockRequests)->toBe([])
        ->and($transport->sentCount())->toBe(1);
});

it('after winning the lock it re-reads the store and uses what another worker just stored instead of exchanging', function () {
    $clock = new FakeClock;
    $store = new RecordingTokenStore($clock);
    $store->afterLock = static function (string $key) use ($store, $clock): void {
        $store->install($key, new AuthToken('ya29.from-another-worker', $clock->now(), $clock->now()->modify('+3600 seconds')));
    };
    $transport = (new FakeTransport)->push(saRulesOk('ya29.never'));

    expect(saRulesProvider($transport, $store, $clock)->token()->value)->toBe('ya29.from-another-worker')
        ->and($transport->sentCount())->toBe(0)
        ->and($store->releases)->toBe(1);
});

it('while another worker holds the lock it keeps the token until 1 s before expiry, exchanges at expiry and leaves their lock alone', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $transport = (new FakeTransport)->push(saRulesOk('ya29.one'), saRulesOk('ya29.two'));
    $provider = saRulesProvider($transport, $store, $clock);
    $provider->token();
    $store->acquireRefreshLock($provider->cacheKey(), 100000);   // another worker, stuck

    $clock->advance(3599);
    expect($provider->token()->value)->toBe('ya29.one')->and($transport->sentCount())->toBe(1);

    $clock->advance(1);   // expires_in reached: an expired token is never served
    expect($provider->token()->value)->toBe('ya29.two')
        ->and($transport->sentCount())->toBe(2)
        ->and($store->acquireRefreshLock($provider->cacheKey(), 30))->toBeFalse();   // still theirs
});

it('after a rejection it adopts a different stored token only while it has not expired', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $transport = (new FakeTransport)->push(saRulesOk('ya29.one'), saRulesOk('ya29.two'));
    $rejected = saRulesProvider($transport, $store, $clock)->token();
    $key = saRulesProvider($transport, $store, $clock)->cacheKey();

    $store->forget($key);
    $store->compareAndSwap($key, null, new AuthToken('ya29.other', $clock->now(), $clock->now()), 60);

    expect(saRulesProvider($transport, $store, $clock)->refreshAfterRejection($rejected)->value)->toBe('ya29.two')
        ->and($transport->sentCount())->toBe(2);
});

it('with the shared store down a rejection is answered by a new exchange', function () {
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(saRulesOk('ya29.one'), saRulesOk('ya29.two'));
    $provider = saRulesProvider($transport, new NullTokenStore, $clock);

    $rejected = $provider->token();

    expect($provider->refreshAfterRejection($rejected)->value)->toBe('ya29.two');
});

it('signs the assertion with the injected signer when one is given', function () {
    $clock = new FakeClock;
    $creds = ServiceAccountCredentials::fromJson(saRulesJson());
    $signer = new CountingSigner(new OpenSslRs256Signer($creds->privateKey(), 'injected-kid'));
    $transport = (new FakeTransport)->push(saRulesOk());

    (new ServiceAccountTokenProvider($creds, $transport, new InMemoryTokenStore($clock), $clock, signer: $signer))->token();

    parse_str($transport->requests()[0]->body, $form);
    $header = json_decode(JwtEncoder::base64UrlDecode(explode('.', (string) $form['assertion'])[0]), true);
    expect($signer->signatures)->toBe(1)->and($header['kid'])->toBe('injected-kid');
});

it('talks to the token endpoint over HTTP/1.1 on its own single-connection pool, or on the injected profile', function () {
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(saRulesOk(), saRulesOk());
    $provider = saRulesProvider($transport, new InMemoryTokenStore($clock), $clock);
    $provider->token();

    $profile = $transport->recorded()[0]['profile'];
    expect($profile->poolKey)->toBe('google-oauth:'.$provider->cacheKey())
        ->and($profile->httpVersion)->toBe(HttpVersion::Http11)
        ->and($profile->requireHttp2)->toBeFalse()
        ->and($profile->maxConnections)->toBe(1);

    $mine = new ConnectionProfile('my-pool', HttpVersion::Http11, requireHttp2: false);
    saRulesProvider($transport, new InMemoryTokenStore($clock), $clock, profile: $mine)->token();
    expect($transport->recorded()[1]['profile'])->toBe($mine);
});

it('reports the curl errno of a transport failure', function () {
    $transport = (new FakeTransport)->push(new TransportFailure(TransportEvidence::Unknown, 28, 'timed out'));
    $clock = new FakeClock;

    expect(fn () => saRulesProvider($transport, new InMemoryTokenStore($clock), $clock)->token())
        ->toThrow(AuthenticationException::class, 'errno 28');
});

it('accepts only a 2xx answer of the token endpoint', function (int $status, bool $accepted) {
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(new Response($status, [], '{"access_token":"ya29.x","expires_in":3600}', '1.1'));
    $get = fn () => saRulesProvider($transport, new InMemoryTokenStore($clock), $clock)->token()->value;

    if ($accepted) {
        expect($get())->toBe('ya29.x');
    } else {
        expect($get)->toThrow(AuthenticationException::class, sprintf('HTTP %d', $status));
    }
})->with([
    '199' => [199, false],
    '200' => [200, true],
    '299' => [299, true],
    '300' => [300, false],
]);

it('takes the lifetime from expires_in, as an integer or a numeric string, down to 1 s', function (mixed $expiresIn, int $lifetime) {
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(saRulesOk('ya29.x', $expiresIn));
    $store = new RecordingTokenStore($clock);

    $token = saRulesProvider($transport, $store, $clock)->token();

    expect($token->expiresAt?->getTimestamp())->toBe($clock->now()->getTimestamp() + $lifetime)
        ->and($store->swaps[0]['ttl'])->toBe($lifetime);
})->with([
    '3600' => [3600, 3600],
    '"1800" (string)' => ['1800', 1800],
    '1 (boundary)' => [1, 1],
]);

it('refuses a malformed 200 answer without leaking the assertion or the key', function (string $body) {
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(new Response(200, [], $body, '1.1'));

    try {
        saRulesProvider($transport, new InMemoryTokenStore($clock), $clock)->token();
        throw new Exception('expected AuthenticationException');
    } catch (AuthenticationException $e) {
        parse_str($transport->requests()[0]->body, $form);
        expect($e->getMessage())->toBe('The Google token exchange returned a malformed response.')
            ->not->toContain((string) $form['assertion'])
            ->not->toContain('PRIVATE KEY');
    }
})->with([
    'empty access_token' => ['{"access_token":"","expires_in":3600}'],
    'access_token not a string' => ['{"access_token":12345,"expires_in":3600}'],
    'expires_in missing' => ['{"access_token":"ya29.x"}'],
    'expires_in 0' => ['{"access_token":"ya29.x","expires_in":0}'],
    'expires_in 0.5 (rounds to 0)' => ['{"access_token":"ya29.x","expires_in":0.5}'],
    'expires_in negative' => ['{"access_token":"ya29.x","expires_in":-5}'],
    'expires_in not numeric' => ['{"access_token":"ya29.x","expires_in":"an hour"}'],
    'a JSON list' => ['["ya29.x",3600]'],
]);

it('a dump shows the identity and cache key of the provider and never the token', function () {
    $clock = new FakeClock;
    $provider = saRulesProvider((new FakeTransport)->push(saRulesOk('ya29.dump-me')), new InMemoryTokenStore($clock), $clock);
    $provider->token();

    expect($provider->__debugInfo())->toBe([
        'identity' => 'push@demo-project.iam.gserviceaccount.com#key-id-1',
        'cacheKey' => $provider->cacheKey(),
        'token' => '[redacted]',
    ])->and(print_r($provider, true))->not->toContain('ya29.dump-me');
});

it('two OAuth scopes, two binding scopes or two keys of one account never share a cache entry', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $t = new FakeTransport;
    $other = new ServiceAccountTokenProvider(ServiceAccountCredentials::fromJson(saRulesJson(['private_key_id' => 'key-id-2'])), $t, $store, $clock);
    $keys = [
        saRulesProvider($t, $store, $clock)->cacheKey(),
        saRulesProvider($t, $store, $clock, 'tenant-2')->cacheKey(),
        saRulesProvider($t, $store, $clock, oauthScope: 'https://www.googleapis.com/auth/cloud-platform')->cacheKey(),
        $other->cacheKey(),
    ];

    expect(array_unique($keys))->toHaveCount(4);
});

// --- ServiceAccountCredentials ----------------------------------------------------------------

it('identifies a service account by client email and key id, with an empty key id when there is none', function () {
    expect(ServiceAccountCredentials::fromJson(saRulesJson())->identity())->toBe('push@demo-project.iam.gserviceaccount.com#key-id-1');

    $data = json_decode(saRulesJson(), true);
    unset($data['private_key_id']);
    expect(ServiceAccountCredentials::fromArray($data)->identity())->toBe('push@demo-project.iam.gserviceaccount.com#');
});

it('treats empty optional fields as absent', function () {
    $creds = ServiceAccountCredentials::fromJson(saRulesJson(['private_key_id' => '', 'project_id' => '', 'token_uri' => '']));

    expect($creds->privateKeyId)->toBeNull()->and($creds->projectId)->toBeNull()->and($creds->tokenUri)->toBeNull();
});

it('refuses an empty client email even with a valid key', function () {
    expect(fn () => new ServiceAccountCredentials('', saRulesKey()['pem'], 'k', 'p'))
        ->toThrow(AuthenticationException::class, 'needs client_email and private_key');
});

it('accepts a credentials file nested 16 levels deep and refuses 17', function () {
    // object + N arrays + the scalar: json_decode counts N + 2 levels
    $nested = static fn (int $arrays): string => substr(saRulesJson(), 0, -1).',"extra":'.str_repeat('[', $arrays).'1'.str_repeat(']', $arrays).'}';

    expect(ServiceAccountCredentials::fromJson($nested(14))->clientEmail)->toBe('push@demo-project.iam.gserviceaccount.com')
        ->and(fn () => ServiceAccountCredentials::fromJson($nested(15)))->toThrow(AuthenticationException::class, 'not valid JSON');
});

it('a dump of the credentials shows who they are and never the key', function () {
    $creds = ServiceAccountCredentials::fromJson(saRulesJson());

    expect($creds->__debugInfo())->toBe([
        'clientEmail' => 'push@demo-project.iam.gserviceaccount.com',
        'privateKey' => '[redacted]',
        'privateKeyId' => 'key-id-1',
        'projectId' => 'demo-project',
    ]);
});
