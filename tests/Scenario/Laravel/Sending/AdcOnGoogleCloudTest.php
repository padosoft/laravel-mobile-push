<?php

declare(strict_types=1);

use Google\Auth\Credentials\GCECredentials;
use Google\Auth\HttpHandler\HttpClientCache;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Psr\Http\Message\RequestInterface;

/*
 * "The application runs on Google Cloud with a service account attached, and no key file anywhere."
 *
 * An FCM connection with `auth => adc` asks google/auth for Application Default Credentials. With no
 * GOOGLE_APPLICATION_CREDENTIALS and no gcloud file, the library probes the metadata server and takes its tokens
 * from there. The metadata server is played here by a handler placed under google/auth's own HTTP client: it answers
 * the two requests the library makes (the probe and the token request) and records them. Nothing leaves the
 * process, and this is not Google Cloud: it shows that the package's side of that path holds together.
 */
beforeEach(function (): void {
    if (! class_exists(GCECredentials::class)) {
        $this->markTestSkipped('google/auth is not installed.');
    }
    $this->seen = [];
    $this->previous = [
        'client' => HttpClientCache::getHttpClient(),
        'adc' => getenv('GOOGLE_APPLICATION_CREDENTIALS'),
        'home' => getenv('HOME'),
    ];
    // No key file, no gcloud login: an empty home directory.
    $this->home = sys_get_temp_dir().'/mp-adc-home-'.bin2hex(random_bytes(5));
    mkdir($this->home, 0700, true);
    putenv('GOOGLE_APPLICATION_CREDENTIALS');
    putenv('HOME='.$this->home);

    $metadata = function (RequestInterface $request): PromiseInterface {
        $this->seen[] = ['uri' => (string) $request->getUri(), 'flavor' => $request->getHeaderLine('Metadata-Flavor')];
        if ($request->getUri()->getHost() !== GCECredentials::METADATA_IP) {
            return Create::promiseFor(new GuzzleResponse(500, [], 'not the metadata server'));
        }
        if (str_contains($request->getUri()->getPath(), '/token')) {
            return Create::promiseFor(new GuzzleResponse(200, ['Content-Type' => 'application/json', 'Metadata-Flavor' => 'Google'],
                '{"access_token":"ya29.from-the-metadata-server","expires_in":3599,"token_type":"Bearer"}'));
        }

        return Create::promiseFor(new GuzzleResponse(200, ['Metadata-Flavor' => 'Google'], 'computeMetadata/'));
    };
    HttpClientCache::setHttpClient(new Client(['handler' => HandlerStack::create($metadata)]));
});

afterEach(function (): void {
    if (! isset($this->previous)) {
        return;
    }
    HttpClientCache::setHttpClient($this->previous['client']);
    putenv($this->previous['adc'] === false ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'GOOGLE_APPLICATION_CREDENTIALS='.$this->previous['adc']);
    putenv($this->previous['home'] === false ? 'HOME' : 'HOME='.$this->previous['home']);
    @rmdir($this->home);
});

it('sends to FCM with the token the metadata server gives, asked for once and with the FCM scope', function (): void {
    $world = W::install(['connections' => ['android' => ['driver' => 'fcm', 'auth' => 'adc', 'project_id' => W::PROJECT]]]);

    $first = W::all(MobilePush::toToken(Platform::Android, 'fcm-registration-token-adc-one')->send(PushMessage::make('Order shipped')));
    $second = W::all(MobilePush::toToken(Platform::Android, 'fcm-registration-token-adc-two')->send(PushMessage::make('Order delivered')));

    $requests = $world->requestsTo('fcm.googleapis.com');
    $tokenRequests = array_values(array_filter($this->seen, static fn (array $r): bool => str_contains($r['uri'], '/token')));

    expect($first[0]->status)->toBe(SendStatus::Accepted)
        ->and($second[0]->status)->toBe(SendStatus::Accepted)
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]->url)->toContain('/v1/projects/'.W::PROJECT.'/messages:send')
        ->and(strtolower($requests[0]->headers['authorization']))->toBe('bearer ya29.from-the-metadata-server')
        ->and($requests[1]->headers['authorization'])->toBe($requests[0]->headers['authorization'])
        // one token for both pushes, asked the way the metadata server wants it
        ->and($tokenRequests)->toHaveCount(1)
        ->and($tokenRequests[0]['flavor'])->toBe('Google')
        ->and(urldecode($tokenRequests[0]['uri']))->toContain('https://www.googleapis.com/auth/firebase.messaging');
});
