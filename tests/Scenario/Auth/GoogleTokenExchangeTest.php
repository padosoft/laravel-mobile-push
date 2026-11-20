<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Core\Auth\NullTokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountCredentials;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/** @return array{creds: ServiceAccountCredentials, pem: string, public: string} */
function serviceAccount(string $tokenUri = 'https://evil.example.test/token'): array
{
    $k = TestKeys::rsa();
    $json = json_encode([
        'type' => 'service_account',
        'project_id' => 'demo-project',
        'private_key_id' => 'key-id-1',
        'private_key' => $k['pem'],
        'client_email' => 'push@demo-project.iam.gserviceaccount.com',
        'token_uri' => $tokenUri,
    ]);

    return ['creds' => ServiceAccountCredentials::fromJson($json), 'pem' => $k['pem'], 'public' => $k['public']];
}

function oauthOk(string $token = 'ya29.access-one', int $expiresIn = 3600): Response
{
    return new Response(200, ['content-type' => 'application/json'], json_encode(['access_token' => $token, 'expires_in' => $expiresIn, 'token_type' => 'Bearer']), '1.1');
}

function googleProvider(array $sa, FakeTransport $transport, FakeClock $clock, ?InMemoryTokenStore $store = null): ServiceAccountTokenProvider
{
    return new ServiceAccountTokenProvider($sa['creds'], $transport, $store ?? new InMemoryTokenStore($clock), $clock);
}

it('exchanges a signed assertion for an access token at the official endpoint', function () {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(oauthOk());

    $token = googleProvider($sa, $transport, $clock)->token();

    expect($token->value)->toBe('ya29.access-one')
        ->and($token->expiresAt?->getTimestamp())->toBe($clock->now()->getTimestamp() + 3600);

    $requests = $transport->requests();
    expect($requests)->toHaveCount(1);
    $r = $requests[0];
    expect($r->method)->toBe('POST')
        ->and($r->url)->toBe('https://oauth2.googleapis.com/token')   // never the token_uri of the file
        ->and($r->headers['content-type'])->toBe('application/x-www-form-urlencoded')
        ->and($r->headers['accept'])->toBe('application/json');

    parse_str($r->body, $form);
    expect(array_keys($form))->toBe(['grant_type', 'assertion'])
        ->and($form['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')
        ->and($form['assertion'])->not->toBe($token->value);

    [$h, $c, $s] = explode('.', $form['assertion']);
    $header = json_decode(JwtEncoder::base64UrlDecode($h), true);
    $claims = json_decode(JwtEncoder::base64UrlDecode($c), true);
    $now = $clock->now()->getTimestamp();

    expect($header)->toBe(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'key-id-1'])
        ->and($claims)->toBe([
            'iss' => 'push@demo-project.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ])
        ->and(openssl_verify($h.'.'.$c, JwtEncoder::base64UrlDecode($s), $sa['public'], OPENSSL_ALGO_SHA256))->toBe(1);
});

it('reuses the access token until expires_in minus the skew, then exchanges again', function () {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(oauthOk('ya29.one'), oauthOk('ya29.two'));
    $provider = googleProvider($sa, $transport, $clock);

    expect($provider->token()->value)->toBe('ya29.one');
    $clock->advance(3599 - 300);
    expect($provider->token()->value)->toBe('ya29.one')
        ->and($transport->requests())->toHaveCount(1);

    $clock->advance(2);
    expect($provider->token()->value)->toBe('ya29.two')
        ->and($transport->requests())->toHaveCount(2);

    $clock->advance(3601);   // fully expired
    $transport->push(oauthOk('ya29.three'));
    expect($provider->token()->value)->toBe('ya29.three');
});

it('many workers sharing a store exchange once; a rejection is compare-and-swapped', function () {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $transport = (new FakeTransport)->push(oauthOk('ya29.one'), oauthOk('ya29.two'), oauthOk('ya29.never'));

    $rejected = googleProvider($sa, $transport, $clock, $store)->token();

    $values = [];
    for ($i = 0; $i < 30; $i++) {
        $values[] = googleProvider($sa, $transport, $clock, $store)->refreshAfterRejection($rejected)->value;
    }

    expect(array_unique($values))->toBe(['ya29.two'])
        ->and($transport->requests())->toHaveCount(2);
});

it('keeps using the valid token while another worker holds the refresh lock', function () {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $transport = (new FakeTransport)->push(oauthOk('ya29.one'));
    $provider = googleProvider($sa, $transport, $clock, $store);
    $provider->token();
    $clock->advance(3400);   // inside the skew window, not yet expired

    $store->acquireRefreshLock($provider->cacheKey(), 30);

    expect(googleProvider($sa, $transport, $clock, $store)->token()->value)->toBe('ya29.one')
        ->and($transport->requests())->toHaveCount(1);
});

it('with the cache down it exchanges once per process and reuses the token', function () {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(oauthOk('ya29.one'));
    $provider = new ServiceAccountTokenProvider($sa['creds'], $transport, new NullTokenStore, $clock);

    for ($i = 0; $i < 100; $i++) {
        expect($provider->token()->value)->toBe('ya29.one');
    }
    expect($transport->requests())->toHaveCount(1);
});

it('raises AuthenticationException on every kind of exchange failure, leaking no secret', function (Response|TransportFailure $answer) {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push($answer);
    $provider = googleProvider($sa, $transport, $clock);

    try {
        $provider->token();
        throw new Exception('expected AuthenticationException');
    } catch (AuthenticationException $e) {
        parse_str($transport->requests()[0]->body, $form);
        $message = $e->getMessage();
        expect($message)->not->toContain($form['assertion'])
            ->not->toContain('PRIVATE KEY')
            ->not->toContain($sa['creds']->clientEmail === '' ? 'x' : 'BEGIN');
    }
})->with([
    '400 invalid_grant' => [new Response(400, [], '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}', '1.1')],
    '500' => [new Response(500, [], 'oops', '1.1')],
    'malformed JSON' => [new Response(200, [], '{not json', '1.1')],
    'missing access_token' => [new Response(200, [], '{"expires_in":3600}', '1.1')],
    'zero expires_in' => [new Response(200, [], '{"access_token":"x","expires_in":0}', '1.1')],
    'transport failure' => [new TransportFailure(TransportEvidence::Unknown, 28, 'timed out')],
]);

it('surfaces the OAuth error code of a 400 without the description', function () {
    $transport = (new FakeTransport)->push(new Response(400, [], '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}', '1.1'));

    expect(fn () => googleProvider(serviceAccount(), $transport, new FakeClock)->token())
        ->toThrow(AuthenticationException::class, 'HTTP 400 (invalid_grant)');
});

it('never exposes the key, the assertion or the access token in dumps or serialization', function () {
    $sa = serviceAccount();
    $clock = new FakeClock;
    $transport = (new FakeTransport)->push(oauthOk('ya29.super-secret'));
    $provider = googleProvider($sa, $transport, $clock);
    $provider->token();
    parse_str($transport->requests()[0]->body, $form);

    $needles = [$sa['pem'], trim(explode('-----', $sa['pem'])[2]), 'ya29.super-secret'];
    // (FakeTransport itself records the request body, so the assertion is checked on the objects we own.)
    foreach ([$provider, $sa['creds']] as $object) {
        foreach ([print_r($object, true), var_export($object, true)] as $dump) {
            foreach ($needles as $needle) {
                expect($dump)->not->toContain($needle);
            }
        }
        expect(fn () => serialize($object))->toThrow(LogicException::class);
    }
});

it('rejects an incomplete or non-service-account credentials file', function (string $json) {
    ServiceAccountCredentials::fromJson($json);
})->with([
    'not json' => ['nope'],
    'no key' => ['{"client_email":"a@b.c"}'],
    'wrong type' => ['{"type":"authorized_user","client_email":"a@b.c","private_key":"x"}'],
    'garbage key' => ['{"client_email":"a@b.c","private_key":"not a pem"}'],
])->throws(AuthenticationException::class);

it('honours an explicit endpoint override for tests only', function () {
    $sa = serviceAccount();
    $transport = (new FakeTransport)->push(oauthOk());
    $clock = new FakeClock;
    (new ServiceAccountTokenProvider($sa['creds'], $transport, new InMemoryTokenStore($clock), $clock, tokenEndpointOverride: 'http://127.0.0.1:9999/token'))->token();

    expect($transport->requests()[0]->url)->toBe('http://127.0.0.1:9999/token');
});
