<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\CurlErrorClassifier;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: things go wrong before or after the request leaves. NotSent is a promise ("you may safely send
 * again, nobody saw it"), so it is given only when the failure provably happened before any request byte;
 * a timeout after the request was written stays Unknown.
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

it('reports a refused TCP connection (nothing listening) as NotSent', function (): void {
    $port = Batch::closedPort();
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = new ConnectionProfile('closed-port', connectTimeoutMs: 1000);

    $byId = Batch::collect($transport->send(Batch::requests("https://127.0.0.1:{$port}", 3), $profile));

    expect($byId)->toHaveCount(3);
    foreach ($byId as [$exchange]) {
        expect($exchange->failure?->evidence)->toBe(TransportEvidence::NotSent)
            ->and($exchange->failure?->errno)->toBe(CurlErrorClassifier::COULDNT_CONNECT)
            ->and($exchange->failure?->requestWritten)->toBeFalse();
    }
});

it('reports a server certificate from an untrusted CA as NotSent and sends nothing', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'untrusted', ['caBundlePath' => $server->foreignCaBundlePath()]);

    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), $profile));
    // The server learns about the aborted handshake a moment after the client has given up: wait for its count.
    $stats = $server->waitFor(static fn (array $stats): bool => $stats['tlsErrors'] >= 1);

    foreach ($byId as [$exchange]) {
        expect($exchange->failure?->evidence)->toBe(TransportEvidence::NotSent)
            ->and($exchange->failure?->errno)->toBe(CurlErrorClassifier::PEER_FAILED_VERIFICATION);
    }
    expect($stats['received'])->toBe(0)->and($stats['tlsErrors'])->toBeGreaterThanOrEqual(1);
});

it('reports a timeout during the TLS handshake as NotSent', function (): void {
    // A TCP listener that never answers the TLS ClientHello: connect succeeds, the handshake never ends.
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    expect($listener)->not->toBeFalse();
    $name = (string) stream_socket_get_name($listener, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);

    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = new ConnectionProfile('tls-hang', connectTimeoutMs: 300, timeoutMs: 2000);
    $byId = Batch::collect($transport->send(Batch::requests("https://127.0.0.1:{$port}", 1), $profile));
    fclose($listener);

    expect($byId['r0'][0]->failure?->errno)->toBe(CurlErrorClassifier::OPERATION_TIMEDOUT)
        ->and($byId['r0'][0]->failure?->evidence)->toBe(TransportEvidence::NotSent)
        ->and($byId['r0'][0]->failure?->requestWritten)->toBeFalse();
});

it('reports a timeout after the request was written as Unknown', function () use (&$server): void {
    $server->rules(['path' => '^/r/1$', 'action' => 'hang']);
    $transport = new CurlTransport(new CurlPoolRegistry);

    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3, timeoutMs: 400), Batch::profile($server, 'hang')));
    $counts = Batch::serverCounts($server->requests());

    expect($byId['r1'][0]->failure?->errno)->toBe(CurlErrorClassifier::OPERATION_TIMEDOUT)
        ->and($byId['r1'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($byId['r1'][0]->failure?->requestWritten)->toBeTrue()
        ->and($byId['r1'][0]->durationMs)->toBeGreaterThanOrEqual(350.0)
        ->and($counts['received']['/r/1'])->toBe(1)
        ->and($byId['r0'][0]->response?->status)->toBe(200)
        ->and($byId['r2'][0]->response?->status)->toBe(200);
});

it('applies the per-request timeout instead of the profile timeout', function () use (&$server): void {
    $server->rules(['action' => 'respond', 'delayMs' => 600]);
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'override', ['timeoutMs' => 5000]);

    $byId = Batch::collect($transport->send([
        new Request('patient', 'POST', $server->baseUrl().'/a', [], '{}'),
        new Request('hurried', 'POST', $server->baseUrl().'/b', [], '{}', timeoutMs: 200),
    ], $profile));

    expect($byId['patient'][0]->response?->status)->toBe(200)
        ->and($byId['hurried'][0]->failure?->errno)->toBe(CurlErrorClassifier::OPERATION_TIMEDOUT);
});

it('refuses plain http and malformed headers locally, as NotSent, without touching the network', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $byId = Batch::collect($transport->send([
        new Request('plain-http', 'POST', 'http://localhost:'.$server->port().'/a', [], '{}'),
        new Request('header-injection', 'POST', $server->baseUrl().'/b', ['x-evil' => "a\r\nx-other: b"], '{}'),
        new Request('bad-method', 'PO ST', $server->baseUrl().'/c', [], '{}'),
    ], Batch::profile($server, 'local-refusals')));

    expect($byId['plain-http'][0]->failure?->evidence)->toBe(TransportEvidence::NotSent)
        ->and($byId['plain-http'][0]->failure?->errno)->toBe(CurlErrorClassifier::UNSUPPORTED_PROTOCOL)
        ->and($byId['header-injection'][0]->failure?->evidence)->toBe(TransportEvidence::NotSent)
        ->and($byId['bad-method'][0]->failure?->evidence)->toBe(TransportEvidence::NotSent)
        ->and($server->stats()['tcpConnections'])->toBe(0);
});
