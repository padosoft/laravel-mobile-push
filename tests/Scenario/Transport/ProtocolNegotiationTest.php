<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\TransportConfigurationException;
use Padosoft\MobilePush\Core\Transport\Curl\CurlCapabilities;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\HttpVersion;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: a profile requires HTTP/2 (APNs only speaks HTTP/2) but the endpoint, e.g. a misconfigured proxy or
 * a wrong host, only negotiates HTTP/1.1. ALPN cannot be restricted to h2 from PHP (measured: even
 * CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE falls back to http/1.1 over TLS), so the first request is necessarily sent.
 * The transport sends ONE pilot request on a pool whose protocol is unknown, reports it as Unknown (it was sent
 * and answered over the wrong protocol), and does not send the rest of the batch (NotSent, provably).
 */

$h1 = null;

beforeAll(function () use (&$h1): void {
    if (MockServer::available()) {
        $h1 = MockServer::start(['mode' => 'h1']);
    }
});

afterAll(function () use (&$h1): void {
    $h1?->stop();
});

beforeEach(function () use (&$h1): void {
    if ($h1 === null) {
        $this->markTestSkipped(MockServer::unavailableReason());
    }
    $h1->reset();
});

it('refuses to report HTTP/1.1 answers as success when HTTP/2 is required, and stops the batch', function () use (&$h1): void {
    $transport = new CurlTransport(new CurlPoolRegistry);

    $byId = Batch::collect($transport->send(Batch::requests($h1->baseUrl(), 50), Batch::profile($h1, 'needs-h2', ['window' => 20])));
    $stats = $h1->stats();

    expect($byId)->toHaveCount(50)
        ->and(array_map('count', $byId))->each->toBe(1)
        ->and($byId['r0'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($byId['r0'][0]->failure?->message)->toContain(CurlTransport::HTTP2_REQUIRED)
        ->and($stats['received'])->toBe(1) // only the pilot left the process
        ->and($stats['alpn'])->toBe(['http/1.1' => 1]);

    foreach (range(1, 49) as $i) {
        expect($byId["r{$i}"][0]->failure?->evidence)->toBe(TransportEvidence::NotSent)
            ->and($byId["r{$i}"][0]->failure?->requestWritten)->toBeFalse();
    }

    // the pool was closed: a later send negotiates again (and is again stopped after one pilot)
    Batch::collect($transport->send(Batch::requests($h1->baseUrl(), 5), Batch::profile($h1, 'needs-h2')));
    expect($h1->stats()['received'])->toBe(2)->and($h1->stats()['connections'])->toBe(2);
});

it('accepts HTTP/1.1 when the profile allows the downgrade', function () use (&$h1): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $byId = Batch::collect($transport->send(Batch::requests($h1->baseUrl(), 5), Batch::profile($h1, 'lenient', ['requireHttp2' => false])));

    foreach ($byId as [$exchange]) {
        expect($exchange->response?->status)->toBe(200)->and($exchange->response?->httpVersion)->toBe('1.1');
    }
});

it('serves plain HTTPS APIs with an HTTP/1.1 profile over keep-alive connections', function () use (&$h1): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($h1, 'oauth', ['httpVersion' => HttpVersion::Http11, 'window' => 50, 'maxConnections' => 2]);

    $byId = Batch::collect($transport->send(Batch::requests($h1->baseUrl(), 40), $profile));
    Batch::collect($transport->send(Batch::requests($h1->baseUrl(), 10), $profile));
    $stats = $h1->stats();

    expect(array_filter($byId, fn (array $e): bool => $e[0]->response?->status === 200))->toHaveCount(40)
        ->and($byId['r0'][0]->response?->httpVersion)->toBe('1.1')
        ->and($stats['received'])->toBe(50)
        ->and($stats['connections'])->toBeLessThanOrEqual(2)
        ->and($stats['maxConcurrentStreams'])->toBe(1); // one request per HTTP/1.1 connection at a time
});

it('throws a configuration error with a remedy before sending anything when HTTP/2 is unavailable', function () use (&$h1): void {
    $noHttp2 = new CurlCapabilities(true, '7.99.0', 0x076300, 'OpenSSL/3.0.13', http2: false, ssl: true, blobOptions: true, missingConstants: []);
    $transport = new CurlTransport(new CurlPoolRegistry, $noHttp2);

    expect(fn () => $transport->send(Batch::requests($h1->baseUrl(), 3), Batch::profile($h1, 'no-h2')))
        ->toThrow(TransportConfigurationException::class, 'nghttp2');
    expect($h1->stats()['tcpConnections'])->toBe(0);
});
