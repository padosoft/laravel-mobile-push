<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlErrorClassifier;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: the provider sheds load. It resets one stream with REFUSED_STREAM, or sends GOAWAY with a
 * last-stream-id below streams we still have in flight. Protocol-wise those streams were not processed,
 * but PHP's curl binding cannot tell these cases apart from a reused connection that died after processing
 * (see docs/transport.md), so they stay Unknown. What must hold: one Exchange per request, no duplicate
 * on the server, and no request reported as a success that the server did not process.
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

it('reports a REFUSED_STREAM as Unknown without libcurl re-sending it', function () use (&$server): void {
    $server->rules(['path' => '^/r/3$', 'action' => 'refuse']);
    $transport = new CurlTransport(new CurlPoolRegistry);

    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 8), Batch::profile($server, 'refused', ['window' => 8])));
    $counts = Batch::serverCounts($server->requests());

    expect($byId)->toHaveCount(8)
        ->and(array_map('count', $byId))->each->toBe(1);

    $refused = $byId['r3'][0];
    expect($refused->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($refused->failure?->errno)->toBe(CurlErrorClassifier::SEND_FAIL_REWIND)
        // the oracle: received exactly once (libcurl 8.5 alone would have retried it 5 times), never processed
        ->and($counts['received']['/r/3'])->toBe(1)
        ->and($counts['processed']['/r/3'] ?? 0)->toBe(0)
        ->and($server->stats()['refused'])->toBe(1);

    foreach ($byId as $id => [$exchange]) {
        if ($id !== 'r3') {
            expect($exchange->response?->status)->toBe(200);
        }
    }
});

it('handles a GOAWAY below in-flight streams: one exchange each, no duplicates, no false success', function () use (&$server): void {
    $server->rules(
        // hold the first responses a little so that several streams are in flight when GOAWAY arrives
        ['path' => '^/r/[0-4]$', 'action' => 'respond', 'delayMs' => 100],
        ['path' => '^/r/5$', 'action' => 'goaway', 'lastStreamId' => 'previous', 'times' => 1],
    );
    $transport = new CurlTransport(new CurlPoolRegistry);

    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 30), Batch::profile($server, 'goaway', ['window' => 10])));
    $counts = Batch::serverCounts($server->requests());
    $stats = $server->stats();

    expect($byId)->toHaveCount(30)
        ->and(array_map('count', $byId))->each->toBe(1)
        ->and($stats['goaways'])->toBe(1)
        ->and($stats['notProcessedAfterGoaway'])->toBeGreaterThanOrEqual(1)
        ->and(max($counts['received']))->toBe(1) // nothing was sent twice
        ->and(max($counts['processed']))->toBe(1);

    $unknown = 0;
    foreach ($byId as $id => [$exchange]) {
        $path = '/r/'.substr($id, 1);
        if ($exchange->hasResponse()) {
            expect($counts['processed'][$path] ?? 0)->toBe(1, "{$id} reported a response the server never produced");
        } else {
            $unknown++;
            expect($exchange->failure?->evidence)->toBe(TransportEvidence::Unknown)
                ->and($counts['processed'][$path] ?? 0)->toBe(0); // above last-stream-id: really not processed
        }
    }
    // Streams opened after the GOAWAY left the server are dropped inside nghttp2 without reaching the
    // application, so the server cannot count them: every one of them is among the Unknown exchanges.
    expect($unknown)->toBeGreaterThanOrEqual($stats['notProcessedAfterGoaway'])
        ->and($unknown + count($counts['processed']))->toBe(30)
        ->and($stats['connections'])->toBe(2); // later requests moved to a fresh connection
});
