<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\ApnsConfig;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Fcm\FcmConfig;
use Padosoft\MobilePush\Core\Fcm\FcmDriver;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * The whole pipeline over the real curl_multi transport against the local HTTP/2 mock server, scripted like APNs
 * and FCM. The request counts are read from the server, not from the sender.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start();
    }
});

afterAll(function () use (&$server): void {
    $server?->stop();
});

beforeEach(function () use (&$server): void {
    if ($server === null) {
        $this->markTestSkipped(MockServer::unavailableReason());
    }
    $server->reset();
});

/** @param  list<Destination>  $destinations */
function stagingTo(SendKit $kit, array $destinations): SendOptions
{
    return SendKit::live(applicationEnvironment: 'testing', configuredMode: 'live', live: new LiveSettings(
        allow: true,
        onlyTo: array_map(fn (Destination $d) => $kit->index->index($d, 'default'), $destinations),
    ));
}

it('sends APNs pushes end to end: accepted with apns-id, 410 with its millisecond timestamp, and a processed-then-dropped push as unknown', function () use (&$server): void {
    $kit = new SendKit;
    [$ok, $gone, $lost] = [SendKit::apnsToken(1), SendKit::apnsToken(2), SendKit::apnsToken(3)];
    $server->rules(
        ['path' => '^/3/device/'.$ok->value().'$', 'action' => 'respond', 'status' => 200, 'headers' => ['apns-id' => '11111111-2222-4333-8444-555555555555'], 'body' => ''],
        ['path' => '^/3/device/'.$gone->value().'$', 'action' => 'respond', 'status' => 410, 'headers' => ['content-type' => 'application/json'], 'body' => '{"reason":"Unregistered","timestamp":1763600000123}'],
        ['path' => '^/3/device/'.$lost->value().'$', 'action' => 'drop'],
    );
    $driver = new ApnsDriver(new ApnsConfig(
        SendKit::apnsBinding(),
        $kit->apnsCredential(),
        window: 1,   // one stream at a time: the dropped connection takes nothing else with it
        connectTimeoutMs: 2000,
        timeoutMs: 5000,
        caBundlePath: $server->caBundlePath(),
        endpointOverride: new EndpointOverride([EndpointRole::ApnsSend->value => $server->baseUrl()], testCredentials: true),
        applicationEnvironment: 'testing',
    ));
    $sender = $kit->sender(new CurlTransport(new CurlPoolRegistry));

    $results = SendKit::all($sender->send($driver, PushMessage::make('Shipped', 'On its way'), [$ok, $gone, $lost], stagingTo($kit, [$ok, $gone, $lost])));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Rejected, SendStatus::OutcomeUnknown])
        ->and($results[0]->providerMessageId)->toBe('11111111-2222-4333-8444-555555555555')
        ->and($results[1]->destinationAction)->toBe(DestinationAction::Invalidate)
        ->and($results[1]->invalidatedAtMs)->toBe(1_763_600_000_123)
        ->and($results[2]->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($results[2]->attempts)->toBe(1);

    $seen = $server->requests();
    $lostOnServer = array_values(array_filter($seen, fn ($r) => str_ends_with($r['path'], $lost->value())));
    expect($seen)->toHaveCount(3)
        ->and($lostOnServer)->toHaveCount(1)
        ->and($lostOnServer[0]['processed'])->toBeTrue()
        ->and(array_column($seen, 'protocol'))->each->toBe('h2')
        ->and($seen[0]['headers']['authorization'])->toStartWith('bearer eyJ')
        ->and($seen[0]['headers']['apns-topic'])->toBe(SendKit::BUNDLE)
        ->and($seen[0]['headers']['apns-push-type'])->toBe('alert');
});

it('sends FCM messages end to end: accepted with the message name, UNREGISTERED invalidates', function () use (&$server): void {
    $kit = new SendKit;
    [$ok, $gone] = [SendKit::fcmToken(1), SendKit::fcmToken(2)];
    $server->rules(
        ['path' => '^/v1/projects/'.SendKit::PROJECT.'/messages:send$', 'times' => 1, 'status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"name":"projects/example-project/messages/0:1"}'],
        ['path' => '^/v1/projects/'.SendKit::PROJECT.'/messages:send$', 'times' => 1, 'status' => 404, 'headers' => ['content-type' => 'application/json'],
            'body' => (string) json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]])],
    );
    $driver = new FcmDriver(new FcmConfig(
        SendKit::fcmBinding(),
        $kit->fcmTokenProvider(SendKit::oauthAnswering('ya29.fake-e2e-token')),
        window: 1,
        connectTimeoutMs: 2000,
        timeoutMs: 5000,
        caBundlePath: $server->caBundlePath(),
        endpointOverride: new EndpointOverride([EndpointRole::FcmApi->value => $server->baseUrl()], testCredentials: true),
        applicationEnvironment: 'testing',
    ), $kit->clock);

    $results = SendKit::all($kit->sender(new CurlTransport(new CurlPoolRegistry))->send($driver, PushMessage::make('Hi', 'There'), [$ok, $gone], stagingTo($kit, [$ok, $gone])));

    $seen = $server->requests();
    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Rejected])
        ->and($results[0]->providerMessageId)->toBe('projects/example-project/messages/0:1')
        ->and($results[1]->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($results[1]->destinationAction)->toBe(DestinationAction::Invalidate)
        ->and($seen)->toHaveCount(2)
        ->and($seen[0]['headers']['authorization'])->toBe('Bearer ya29.fake-e2e-token')
        ->and(json_decode($seen[0]['body'], true)['message']['token'])->toBe($ok->value())
        ->and(json_decode($seen[1]['body'], true)['message']['token'])->toBe($gone->value());
});
