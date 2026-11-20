<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: the provider accepts and processes a push, then the connection drops before the response
 * reaches us. We cannot know the outcome, so it must be Unknown, never NotSent and never a success,
 * and above all the push must not be delivered twice behind our back.
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

it('reports a processed request whose response was lost as Unknown, and the rest of the batch still completes', function () use (&$server): void {
    $server->rules(['path' => '^/r/4$', 'action' => 'drop', 'times' => 1]);
    $transport = new CurlTransport(new CurlPoolRegistry);

    // window 1: the story is about one request; nothing else is in flight on the dropped connection
    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 10), Batch::profile($server, 'lost', ['window' => 1])));
    $counts = Batch::serverCounts($server->requests());

    expect(array_keys($byId))->toHaveCount(10)
        ->and(array_map('count', $byId))->each->toBe(1);

    $lost = $byId['r4'][0];
    expect($lost->hasResponse())->toBeFalse()
        ->and($lost->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($lost->failure?->requestWritten)->toBeTrue()
        // the oracle: the server did process it, exactly once
        ->and($counts['processed']['/r/4'])->toBe(1)
        ->and($counts['received']['/r/4'])->toBe(1);

    foreach ($byId as $id => [$exchange]) {
        if ($id !== 'r4') {
            expect($exchange->response?->status)->toBe(200);
        }
    }
    // the requests after the drop travelled on a fresh connection
    expect($server->stats()['connections'])->toBe(2);
});

it('never re-sends a request behind our back when a busy multiplexed connection dies', function () use (&$server): void {
    // Measured with libcurl 8.5: with an in-memory body (CURLOPT_POSTFIELDS) libcurl silently retries
    // in-flight streams of a dying reused connection that had no response byte yet, and the server processed
    // a request twice. The transport streams bodies without a seek callback so that re-send cannot happen.
    $server->rules(['path' => '^/r/10$', 'action' => 'drop', 'times' => 1]);
    $transport = new CurlTransport(new CurlPoolRegistry);

    for ($round = 0; $round < 3; $round++) {
        $server->reset()->rules(['path' => '^/r/10$', 'action' => 'drop', 'times' => 1]);
        $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 40), Batch::profile($server, 'busy', ['window' => 40])));
        $counts = Batch::serverCounts($server->requests());

        expect($byId)->toHaveCount(40)
            ->and(array_map('count', $byId))->each->toBe(1)
            ->and(max($counts['processed']))->toBe(1) // no request was processed twice
            ->and(max($counts['received']))->toBe(1); // ... or even sent twice

        foreach ($byId as $id => [$exchange]) {
            $path = '/r/'.substr($id, 1);
            if ($exchange->hasResponse()) {
                expect($counts['processed'][$path] ?? 0)->toBe(1);
            } else {
                // lost with the connection: maybe processed (the server says so for some), never "not sent"
                expect($exchange->failure?->evidence)->toBe(TransportEvidence::Unknown);
            }
        }
        expect($byId['r10'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown);
    }
});

it('reports a response cut in the middle of its body as Unknown, not as a truncated success', function () use (&$server): void {
    $server->rules(['path' => '^/r/1$', 'action' => 'close-mid-body', 'status' => 200, 'times' => 1]);
    $transport = new CurlTransport(new CurlPoolRegistry);

    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), Batch::profile($server, 'midbody', ['window' => 1])));

    expect($byId['r1'][0]->hasResponse())->toBeFalse()
        ->and($byId['r1'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($byId['r0'][0]->response?->status)->toBe(200)
        ->and($byId['r2'][0]->response?->status)->toBe(200)
        ->and(Batch::serverCounts($server->requests())['processed']['/r/1'])->toBe(1);
});
