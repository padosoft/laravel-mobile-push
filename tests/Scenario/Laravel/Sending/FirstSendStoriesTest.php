<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\Events\AuthTokenRefreshed;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Laravel\Sending\PendingSend;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('is configured from the environment only: an iPhone token reaches APNs for the bundle, an Android token reaches FCM for the project', function () {
    // Exactly what the commented example in config/mobile-push.php produces from a .env file.
    $world = W::install([
        'connections' => ['ios' => W::apns(), 'android' => W::fcm()],
    ]);
    $iphone = W::apnsToken('iphone');

    $ios = W::all(MobilePush::toToken(Platform::Ios, $iphone)->send(PushMessage::make('Order shipped', 'Arrives Friday')));
    $android = W::all(MobilePush::toToken(Platform::Android, 'fake-android-registration-token-1')->send(PushMessage::make('Order shipped', 'Arrives Friday')));

    $apns = $world->requestsTo('api.push.apple.com');
    $fcm = $world->requestsTo('fcm.googleapis.com');

    expect($ios)->toHaveCount(1)
        ->and($ios[0]->status)->toBe(SendStatus::Accepted)
        ->and($android[0]->status)->toBe(SendStatus::Accepted)
        ->and($apns)->toHaveCount(1)
        ->and($apns[0]->url)->toBe('https://api.push.apple.com/3/device/'.$iphone)
        ->and($apns[0]->headers['apns-topic'])->toBe(W::BUNDLE)
        ->and(W::jwtHeader($apns[0])['kid'] ?? null)->toBe('KEY1111111')
        ->and($fcm)->toHaveCount(1)
        ->and($fcm[0]->url)->toBe('https://fcm.googleapis.com/v1/projects/'.W::PROJECT.'/messages:send')
        ->and(json_decode($fcm[0]->body, true)['message']['token'])->toBe('fake-android-registration-token-1')
        ->and($fcm[0]->headers['authorization'])->toBe('Bearer ya29.fake-access-token');
});

it('gives a junior on a laptop a simulated send with zero requests and a hint that explains how to go live', function () {
    $world = W::install(['connections' => ['ios' => W::apns()]], environment: 'local');
    config()->set('mobile-push.mode', 'auto');
    W::forgetSingletons();

    $results = W::all(MobilePush::toToken(Platform::Ios, W::apnsToken('laptop'))->send(PushMessage::make('Hello')));

    expect($world->transport->sentCount())->toBe(0)
        ->and($results[0]->status)->toBe(SendStatus::Simulated)
        ->and($results[0]->reason->hint)->toContain('MOBILE_PUSH_MODE=live')
        ->and($results[0]->reason->hint)->toContain('live.only_to');
});

it('sends for real outside production only to the allowlisted test device', function () {
    $tester = W::apnsToken('qa-phone');
    $stranger = W::apnsToken('someone-else');
    $world = W::install(['connections' => ['ios' => W::apns(extra: ['live' => ['allow' => true, 'only_to' => [$tester]]])]], environment: 'staging');

    $results = W::all(MobilePush::toTokens(Platform::Ios, [$tester, $stranger])->send(PushMessage::make('Staging check')));

    expect($world->pushRequests())->toHaveCount(1)
        ->and($world->pushRequests()[0]->url)->toEndWith('/3/device/'.$tester)
        ->and($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($results[1]->status)->toBe(SendStatus::Skipped)
        ->and($results[1]->reason->code)->toBe(ReasonCode::PolicySafeMode)
        ->and($results[1]->reason->hint)->toContain('live.only_to');
});

it('never guesses the platform: an iOS-shaped token sent as Android goes to FCM as a registration token', function () {
    $world = W::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $hex = W::apnsToken('looks-like-an-iphone');

    $parameter = (new ReflectionMethod(PendingSend::class, 'toToken'))->getParameters()[0];
    $result = W::all(MobilePush::toToken(Platform::Android, $hex)->send(PushMessage::make('Hi')))[0];

    expect((string) $parameter->getType())->toBe(Platform::class)
        ->and($parameter->allowsNull())->toBeFalse()
        ->and($result->status)->toBe(SendStatus::Accepted)
        ->and($world->requestsTo('api.push.apple.com'))->toBeEmpty()
        ->and($world->requestsTo('fcm.googleapis.com'))->toHaveCount(1)
        ->and(json_decode($world->requestsTo('fcm.googleapis.com')[0]->body, true)['message']['token'])->toBe($hex);
});

it('asks to name the connection when two apps of the same platform are configured, before any request', function () {
    $world = W::install(['connections' => ['consumer' => W::apns(), 'business' => W::apns(keyId: 'KEY2222222', bundle: 'com.example.business')]]);

    expect(fn () => MobilePush::toToken(Platform::Ios, W::apnsToken('x'))->send(PushMessage::make('Hi')))
        ->toThrow(ConfigurationException::class, 'name the one to use with MobilePush::connection');
    expect($world->transport->sentCount())->toBe(0);

    MobilePush::connection('business')->toToken(Platform::Ios, W::apnsToken('x'))->send(PushMessage::make('Hi'))->counts();
    expect($world->pushRequests()[0]->headers['apns-topic'])->toBe('com.example.business');
});

it('sends through the helpers and to an FCM topic as documented', function () {
    $world = W::install(['connections' => ['ios' => W::apns(), 'x' => W::fcm()]]);
    $token = W::apnsToken('helper');

    $report = mobile_push_to_token(Platform::Ios, $token, 'Title', 'Body', ['order' => '42']);
    $topic = MobilePush::connection('x')->toTopic('news')->send(PushMessage::make('Breaking', 'News'));
    $topicResults = W::all($topic);

    $apns = $world->requestsTo('api.push.apple.com');
    $fcm = $world->requestsTo('fcm.googleapis.com');

    expect($report->allAccepted())->toBeTrue()
        ->and(json_decode($apns[0]->body, true))->toMatchArray(['order' => '42'])
        ->and(json_decode($apns[0]->body, true)['aps']['alert'])->toBe(['title' => 'Title', 'body' => 'Body'])
        ->and($topicResults[0]->status)->toBe(SendStatus::Accepted)
        ->and(json_decode($fcm[0]->body, true)['message']['topic'])->toBe('news')
        ->and(mobile_push())->toBeInstanceOf(PushManager::class)
        ->and(mobile_push(ApnsDeviceToken::of($token)))->toBeInstanceOf(PendingSend::class);
});

it('returns an empty report, not an error, for a user without devices', function () {
    $world = W::install(['connections' => ['ios' => W::apns()]])->withDevices();

    $report = MobilePush::to(Customer::withId(7))->send(PushMessage::make('Hi'));

    expect($report->count())->toBe(0)
        ->and($world->transport->sentCount())->toBe(0);
});

it('tells listeners about a token refresh and the completed batch, with counters and no token', function () {
    $world = W::install(['connections' => ['ios' => W::apns()]]);
    $answers = [
        new Response(403, [], '{"reason":"ExpiredProviderToken"}'),
    ];
    $world->answer = function () use (&$answers): ?Response {
        return array_shift($answers);
    };

    $report = MobilePush::toTokens(Platform::Ios, [W::apnsToken('a'), W::apnsToken('b')])->send(PushMessage::make('Hi'));
    $results = W::all($report);

    $refreshed = $world->eventsOf(AuthTokenRefreshed::class);
    $batches = $world->eventsOf(PushBatchCompleted::class);
    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and($refreshed)->toHaveCount(1)
        ->and($refreshed[0]->credentialIdentity)->toBe('token:'.W::TEAM.':KEY1111111')
        ->and($batches)->toHaveCount(1)
        ->and($batches[0]->total)->toBe(2)
        ->and($batches[0]->counts['accepted'])->toBe(2)
        ->and($world->eventsOf(PushResultProduced::class))->toHaveCount(2);
});
