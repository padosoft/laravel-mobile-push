<?php

declare(strict_types=1);

use Google\Auth\Credentials\ExternalAccountCredentials;
use Google\Auth\HttpHandler\HttpClientCache;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Fcm\Auth\GoogleAuthLibraryProvider;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Psr\Http\Message\RequestInterface;

/*
 * "The application runs outside Google Cloud (another cloud, Kubernetes, CI) and has no key file: the workload
 * proves who it is with its own identity token and Google exchanges it."
 *
 * GOOGLE_APPLICATION_CREDENTIALS points to an `external_account` file (Workload Identity Federation). With
 * `auth => adc` google/auth reads the workload's subject token from a file, exchanges it at the STS endpoint
 * (optionally trades the federated token for a service-account token through IAM Credentials) and the package sends
 * to FCM with the result. STS and IAM are played here by a handler placed under google/auth's own HTTP client:
 * nothing leaves the process, and this is not Google's STS: it shows the package's side of that path holds together.
 */
const WIF_SUBJECT_TOKEN = 'subject-token-from-the-workload';
const WIF_STS_TOKEN = 'ya29.federated-token-from-sts';
const WIF_IMPERSONATED_TOKEN = 'ya29.service-account-token-from-iam';
const WIF_AUDIENCE = '//iam.googleapis.com/projects/123456789/locations/global/workloadIdentityPools/example-pool/providers/example-provider';
const WIF_IMPERSONATION_URL = 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/push@example-shop.iam.gserviceaccount.com:generateAccessToken';

beforeEach(function (): void {
    if (! class_exists(ExternalAccountCredentials::class)) {
        $this->markTestSkipped('google/auth is not installed.');
    }
    $this->seen = [];
    $this->previous = [
        'client' => HttpClientCache::getHttpClient(),
        'adc' => getenv('GOOGLE_APPLICATION_CREDENTIALS'),
        'home' => getenv('HOME'),
    ];
    $dir = sys_get_temp_dir().'/mp-wif-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $this->dir = $dir;
    $this->subjectFile = $dir.'/subject-token.txt';
    $this->credentialFile = $dir.'/external-account.json';
    file_put_contents($this->subjectFile, WIF_SUBJECT_TOKEN);
    putenv('HOME='.$dir); // no gcloud login to fall back on

    $this->writeCredential = function (bool $impersonate): void {
        $json = [
            'type' => 'external_account',
            'audience' => WIF_AUDIENCE,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
            'token_url' => 'https://sts.googleapis.com/v1/token',
            'credential_source' => ['file' => $this->subjectFile],
        ];
        if ($impersonate) {
            $json['service_account_impersonation_url'] = WIF_IMPERSONATION_URL;
        }
        file_put_contents($this->credentialFile, json_encode($json));
        putenv('GOOGLE_APPLICATION_CREDENTIALS='.$this->credentialFile);
    };

    $sts = function (RequestInterface $request): PromiseInterface {
        $body = (string) $request->getBody();
        parse_str($body, $form);
        $isJson = str_contains($request->getHeaderLine('Content-Type'), 'json');
        if ($isJson) {
            $form = (array) json_decode($body, true);
        }
        $this->seen[] = ['uri' => (string) $request->getUri(), 'form' => $form, 'authorization' => $request->getHeaderLine('Authorization')];

        return match ($request->getUri()->getHost()) {
            'sts.googleapis.com' => Create::promiseFor(new GuzzleResponse(200, ['Content-Type' => 'application/json'],
                (string) json_encode(['access_token' => WIF_STS_TOKEN, 'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token', 'token_type' => 'Bearer', 'expires_in' => 3599]))),
            'iamcredentials.googleapis.com' => Create::promiseFor(new GuzzleResponse(200, ['Content-Type' => 'application/json'],
                (string) json_encode(['accessToken' => WIF_IMPERSONATED_TOKEN, 'expireTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 3599)]))),
            default => Create::promiseFor(new GuzzleResponse(500, [], 'unexpected host')),
        };
    };
    HttpClientCache::setHttpClient(new Client(['handler' => HandlerStack::create($sts)]));
});

afterEach(function (): void {
    if (! isset($this->previous)) {
        return;
    }
    HttpClientCache::setHttpClient($this->previous['client']);
    putenv($this->previous['adc'] === false ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'GOOGLE_APPLICATION_CREDENTIALS='.$this->previous['adc']);
    putenv($this->previous['home'] === false ? 'HOME' : 'HOME='.$this->previous['home']);
    foreach ([$this->subjectFile, $this->credentialFile] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

it('sends to FCM with the token Google STS gives for the workload\'s subject token, exchanged once', function (): void {
    ($this->writeCredential)(false);
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    $connection = ['driver' => 'fcm', 'auth' => 'adc', 'project_id' => W::PROJECT];
    $world = W::install(['connections' => ['android' => $connection]]);

    $first = W::all(MobilePush::toToken(Platform::Android, 'fcm-registration-token-wif-one')->send(PushMessage::make('Order shipped')));
    $second = W::all(MobilePush::toToken(Platform::Android, 'fcm-registration-token-wif-two')->send(PushMessage::make('Order delivered')));

    $requests = $world->requestsTo('fcm.googleapis.com');
    $exchanges = array_values(array_filter($this->seen, static fn (array $r): bool => str_contains($r['uri'], 'sts.googleapis.com')));

    expect($first[0]->status)->toBe(SendStatus::Accepted)
        ->and($second[0]->status)->toBe(SendStatus::Accepted)
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]->url)->toContain('/v1/projects/'.W::PROJECT.'/messages:send')
        ->and($requests[0]->headers['authorization'])->toBe('Bearer '.WIF_STS_TOKEN)
        ->and($requests[1]->headers['authorization'])->toBe($requests[0]->headers['authorization'])
        // what Google's STS was asked: a token exchange of the file's subject token, for the pool provider, with the FCM scope
        ->and($exchanges)->toHaveCount(1)
        ->and($this->seen)->toHaveCount(1)
        ->and($exchanges[0]['uri'])->toBe('https://sts.googleapis.com/v1/token')
        ->and($exchanges[0]['form']['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:token-exchange')
        ->and($exchanges[0]['form']['audience'])->toBe(WIF_AUDIENCE)
        ->and($exchanges[0]['form']['subject_token'])->toBe(WIF_SUBJECT_TOKEN)
        ->and($exchanges[0]['form']['subject_token_type'])->toBe('urn:ietf:params:oauth:token-type:jwt')
        ->and($exchanges[0]['form']['requested_token_type'])->toBe('urn:ietf:params:oauth:token-type:access_token')
        ->and($exchanges[0]['form']['scope'])->toContain('https://www.googleapis.com/auth/firebase.messaging');

    // no token value in any log record nor in anything the package lets you dump
    $provider = GoogleAuthLibraryProvider::applicationDefault(new InMemoryTokenStore($world->clock), $world->clock);
    $provider->token();
    $parts = ['logs' => implode("\n", $logged), 'events' => print_r($world->events, true), 'provider' => print_r($provider, true).print_r(app(ConnectionFactory::class)->make('default', 'android', $connection), true)];
    foreach ($parts as $where => $dump) {
        foreach ([WIF_SUBJECT_TOKEN, WIF_STS_TOKEN, 'fcm-registration-token-wif-one'] as $secret) {
            expect(str_contains($dump, $secret))->toBeFalse($where.' leaks '.$secret);
        }
    }
});

it('sends with the service-account token when the credential asks to impersonate one', function (): void {
    ($this->writeCredential)(true);
    $world = W::install(['connections' => ['android' => ['driver' => 'fcm', 'auth' => 'adc', 'project_id' => W::PROJECT]]]);

    $first = W::all(MobilePush::toToken(Platform::Android, 'fcm-registration-token-wif-three')->send(PushMessage::make('Order shipped')));
    $second = W::all(MobilePush::toToken(Platform::Android, 'fcm-registration-token-wif-four')->send(PushMessage::make('Order delivered')));

    $requests = $world->requestsTo('fcm.googleapis.com');
    $sts = array_values(array_filter($this->seen, static fn (array $r): bool => str_contains($r['uri'], 'sts.googleapis.com')));
    $iam = array_values(array_filter($this->seen, static fn (array $r): bool => str_contains($r['uri'], 'iamcredentials.googleapis.com')));

    expect($first[0]->status)->toBe(SendStatus::Accepted)
        ->and($second[0]->status)->toBe(SendStatus::Accepted)
        ->and($requests)->toHaveCount(2)
        // FCM gets the service account's token, not the federated one
        ->and($requests[0]->headers['authorization'])->toBe('Bearer '.WIF_IMPERSONATED_TOKEN)
        ->and($requests[1]->headers['authorization'])->toBe($requests[0]->headers['authorization'])
        // one STS exchange and one impersonation call for both pushes; IAM is called with the federated token
        ->and($sts)->toHaveCount(1)
        ->and($sts[0]['form']['subject_token'])->toBe(WIF_SUBJECT_TOKEN)
        ->and($iam)->toHaveCount(1)
        ->and($iam[0]['uri'])->toBe(WIF_IMPERSONATION_URL)
        ->and($iam[0]['authorization'])->toBe('Bearer '.WIF_STS_TOKEN)
        ->and($iam[0]['form']['scope'])->toContain('https://www.googleapis.com/auth/firebase.messaging');
});
