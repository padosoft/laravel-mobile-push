<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: certificate-based APNs authentication. The client certificate and key live in memory (decrypted
 * from the credential store) and are handed to libcurl as blobs: no temp file ever holds the key.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['mtls' => true]);
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

it('authenticates with an in-memory client certificate and key', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'cert-a', [
        'clientCertificatePem' => $server->clientCertificatePem(),
        'clientKeyPem' => $server->clientKeyPem(),
    ]);
    $tempBefore = glob(sys_get_temp_dir().'/*') ?: [];

    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 10), $profile));

    expect(array_filter($byId, fn (array $e): bool => $e[0]->response?->status === 200))->toHaveCount(10)
        ->and($server->stats()['connections'])->toBe(1)
        ->and($server->stats()['tlsErrors'])->toBe(0)
        ->and(array_diff(glob(sys_get_temp_dir().'/*') ?: [], $tempBefore))->toBe([]);
});

it('authenticates with an encrypted private key and its passphrase', function () use (&$server): void {
    $key = openssl_pkey_get_private($server->clientKeyPem());
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $encrypted, 'correct horse');

    $transport = new CurlTransport(new CurlPoolRegistry);
    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), Batch::profile($server, 'cert-enc', [
        'clientCertificatePem' => $server->clientCertificatePem(),
        'clientKeyPem' => $encrypted,
        'clientKeyPassphrase' => 'correct horse',
    ])));

    expect($byId['r0'][0]->response?->status)->toBe(200);
});

it('fails without a client certificate, and the request never reaches the application', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 1), Batch::profile($server, 'no-cert')));

    // TLS 1.3: the client finishes its side of the handshake and writes the request before the server's
    // "certificate required" alert arrives, so libcurl cannot prove it was not sent. It stays Unknown.
    expect($byId['r0'][0]->hasResponse())->toBeFalse()
        ->and($byId['r0'][0]->failure?->evidence)->toBe(TransportEvidence::Unknown)
        ->and($server->stats()['received'])->toBe(0)
        ->and($server->stats()['tlsErrors'])->toBe(1);
});
