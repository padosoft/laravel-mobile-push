<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: one batch, many fates. Accepted pushes, rejected tokens, a throttled request, a server error and a
 * request lost to the network all travel together; each gets its own exact result, headers and body, and the
 * server receives exactly the bytes we meant to send.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['proxy' => true]);
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

it('gives every request of a mixed batch its own independent result', function () use (&$server): void {
    $server->rules(
        ['path' => '^/3/device/bad$', 'status' => 400, 'headers' => ['apns-id' => 'id-bad', 'content-type' => 'application/json'], 'body' => '{"reason":"BadDeviceToken"}'],
        ['path' => '^/3/device/gone$', 'status' => 410, 'headers' => ['apns-id' => 'id-gone'], 'body' => '{"reason":"Unregistered","timestamp":1700000000000}'],
        ['path' => '^/3/device/slow-down$', 'status' => 429, 'headers' => ['retry-after' => '30', 'x-multi' => 'one'], 'body' => '{"reason":"TooManyRequests"}'],
        ['path' => '^/3/device/broken$', 'status' => 503, 'body' => 'upstream unavailable'],
        ['path' => '^/3/device/lost$', 'action' => 'hang'],
        ['path' => '^/3/device/ok', 'status' => 200, 'headers' => ['apns-id' => 'id-ok', 'apns-unique-id' => 'u-1'], 'body' => ''],
    );
    $url = fn (string $p): string => $server->baseUrl().'/3/device/'.$p;
    $requests = [
        new Request('ok-1', 'POST', $url('ok-1'), ['apns-topic' => 'com.example.app', 'apns-push-type' => 'alert', 'content-type' => 'application/json'], '{"aps":{"alert":"one"}}'),
        new Request('bad', 'POST', $url('bad'), ['apns-topic' => 'com.example.app'], '{"aps":{}}'),
        new Request('gone', 'POST', $url('gone'), [], '{"aps":{}}'),
        new Request('slow-down', 'POST', $url('slow-down'), [], '{"aps":{}}'),
        new Request('broken', 'POST', $url('broken'), [], '{"aps":{}}'),
        new Request('lost', 'POST', $url('lost'), [], '{"aps":{}}', timeoutMs: 300),
        new Request('ok-2', 'POST', $url('ok-2'), ['Apns-Priority' => '5'], '{"aps":{"alert":"two"}}'),
    ];

    $byId = Batch::collect((new CurlTransport(new CurlPoolRegistry))->send($requests, Batch::profile($server, 'mixed')));

    expect($byId)->toHaveCount(7)->and(array_map('count', $byId))->each->toBe(1);

    $ok = $byId['ok-1'][0]->response;
    expect($ok?->status)->toBe(200)
        ->and($ok?->header('APNS-ID'))->toBe('id-ok')
        ->and($ok?->headers['apns-unique-id'])->toBe('u-1')
        ->and($ok?->body)->toBe('')
        ->and($ok?->httpVersion)->toBe('2');

    expect($byId['bad'][0]->response?->status)->toBe(400)
        ->and($byId['bad'][0]->response?->json())->toBe(['reason' => 'BadDeviceToken'])
        ->and($byId['bad'][0]->response?->header('apns-id'))->toBe('id-bad')
        ->and($byId['gone'][0]->response?->status)->toBe(410)
        ->and($byId['gone'][0]->response?->json())->toBe(['reason' => 'Unregistered', 'timestamp' => 1700000000000])
        ->and($byId['slow-down'][0]->response?->status)->toBe(429)
        ->and($byId['slow-down'][0]->response?->header('retry-after'))->toBe('30')
        ->and($byId['broken'][0]->response?->status)->toBe(503)
        ->and($byId['broken'][0]->response?->body)->toBe('upstream unavailable')
        ->and($byId['broken'][0]->response?->json())->toBeNull()
        ->and($byId['lost'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($byId['ok-2'][0]->response?->status)->toBe(200);

    // the oracle: what the server actually received
    $received = [];
    foreach ($server->requests() as $r) {
        $received[$r['path']] = $r;
    }
    expect($received)->toHaveCount(7)
        ->and($received['/3/device/ok-1']['method'])->toBe('POST')
        ->and($received['/3/device/ok-1']['body'])->toBe('{"aps":{"alert":"one"}}')
        ->and($received['/3/device/ok-1']['headers']['apns-topic'])->toBe('com.example.app')
        ->and($received['/3/device/ok-1']['headers']['apns-push-type'])->toBe('alert')
        ->and($received['/3/device/ok-1']['headers']['content-length'])->toBe('23')
        ->and($received['/3/device/ok-2']['headers']['apns-priority'])->toBe('5')
        ->and($received['/3/device/bad']['headers'])->not->toHaveKey('expect')
        // no libcurl default content type sneaks in
        ->and($received['/3/device/gone']['headers'])->not->toHaveKey('content-type');
});

it('sends GET, DELETE and empty-body POST requests correctly', function () use (&$server): void {
    $server->rules(['method' => 'GET', 'status' => 200, 'body' => '{"channels":["a"]}'], ['method' => 'DELETE', 'status' => 204, 'body' => '']);
    $byId = Batch::collect((new CurlTransport(new CurlPoolRegistry))->send([
        new Request('list', 'GET', $server->baseUrl().'/channels'),
        new Request('delete', 'DELETE', $server->baseUrl().'/channels/a'),
        new Request('empty-post', 'POST', $server->baseUrl().'/empty'),
    ], Batch::profile($server, 'verbs')));
    $received = array_column($server->requests(), null, 'path');

    expect($byId['list'][0]->response?->json())->toBe(['channels' => ['a']])
        ->and($byId['delete'][0]->response?->status)->toBe(204)
        ->and($byId['empty-post'][0]->response?->status)->toBe(200)
        ->and($received['/channels']['method'])->toBe('GET')
        ->and($received['/channels/a']['method'])->toBe('DELETE')
        ->and($received['/empty']['method'])->toBe('POST')
        ->and($received['/empty']['body'])->toBe('');
});

it('tunnels through an explicit HTTP proxy and parses only the final response headers', function () use (&$server): void {
    $server->rules(['status' => 200, 'headers' => ['apns-id' => 'via-proxy'], 'body' => '{}']);
    $transport = new CurlTransport(new CurlPoolRegistry);
    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 5), Batch::profile($server, 'proxied', ['proxy' => $server->proxyUrl()])));

    foreach ($byId as [$exchange]) {
        expect($exchange->response?->status)->toBe(200)
            ->and($exchange->response?->header('apns-id'))->toBe('via-proxy')
            ->and($exchange->response?->headers)->not->toHaveKey('proxy-agent')
            ->and($exchange->response?->httpVersion)->toBe('2');
    }
    expect($server->stats()['proxyConnects'])->toBe(1)->and($server->stats()['connections'])->toBe(1);
});

it('aborts an oversized response body instead of buffering it', function () use (&$server): void {
    $server->rules(['status' => 200, 'body' => str_repeat('x', CurlTransport::MAX_RESPONSE_BYTES + 1024)]);
    $byId = Batch::collect((new CurlTransport(new CurlPoolRegistry))->send(Batch::requests($server->baseUrl(), 1), Batch::profile($server, 'huge')));

    expect($byId['r0'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($byId['r0'][0]->failure?->message)->toContain('larger than');
});
