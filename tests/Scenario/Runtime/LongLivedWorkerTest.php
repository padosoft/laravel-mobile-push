<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: a queue worker lives for a day. It runs one small send after another on the same credential (one pool key),
 * with quiet moments in between. It must keep ONE connection for the whole run, reconnect only for the documented
 * reasons (idle longer than the pool's max idle time, the server closing the connection), and its memory and open
 * descriptors must stay flat once it is warm. Connections and requests are counted by the server.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['recordLimit' => 10]);
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

/**
 * Runs $batches sends of $perBatch requests; returns failures, memory and descriptors after warm-up and at the end.
 *
 * @return array{failures: int, exchanges: int, memWarm: int, memEnd: int, fdWarm: ?int, fdEnd: ?int}
 */
function liveForADay(CurlTransport $transport, MockServer $server, int $batches, int $perBatch, int $warmUp, int $pauseEvery = 0, int $pauseMs = 0): array
{
    $profile = Batch::profile($server, 'worker-day', ['window' => 10]);
    $failures = 0;
    $exchanges = 0;
    $memWarm = 0;
    $fdWarm = null;
    for ($b = 0; $b < $batches; $b++) {
        foreach ($transport->send(Batch::requests($server->baseUrl(), $perBatch), $profile) as $exchange) {
            $exchanges++;
            $failures += $exchange->response?->status === 200 ? 0 : 1;
        }
        if ($b + 1 === $warmUp) {
            $memWarm = RuntimeProbe::memory();
            $fdWarm = RuntimeProbe::openDescriptors();
        }
        if ($pauseEvery > 0 && ($b + 1) % $pauseEvery === 0) {
            usleep($pauseMs * 1000); // the worker waits for the next job
        }
    }

    return [
        'failures' => $failures,
        'exchanges' => $exchanges,
        'memWarm' => $memWarm,
        'memEnd' => RuntimeProbe::memory(),
        'fdWarm' => $fdWarm,
        'fdEnd' => RuntimeProbe::openDescriptors(),
    ];
}

it('keeps one connection for 2,000 consecutive sends with idle gaps, with flat memory and descriptors', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);

    $run = liveForADay($transport, $server, batches: 2000, perBatch: 5, warmUp: 200, pauseEvery: 250, pauseMs: 300);

    $stats = $server->stats();
    expect($run['failures'])->toBe(0)
        ->and($run['exchanges'])->toBe(10_000)
        ->and($stats['received'])->toBe(10_000)
        ->and($stats['connections'])->toBe(1)
        ->and($stats['openConnections'])->toBe(1)
        ->and($run['memEnd'] - $run['memWarm'])->toBeLessThan(256 * 1024);
    if ($run['fdWarm'] !== null) {
        expect($run['fdEnd'])->toBe($run['fdWarm']);
    }
});

it('reconnects exactly once after an idle period longer than the pool max idle time, and closes the old connection', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'short-idle', ['maxIdleSeconds' => 1]);

    $first = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));
    usleep(600_000);
    $second = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile)); // still within 1 s
    usleep(1_300_000); // longer than maxIdleSeconds: the registry expires the pool on the next access
    $third = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));

    $stats = RuntimeProbe::settle($server, 'openConnections', 1);
    expect(array_map(fn (array $e): ?int => $e[0]->response?->status, [...array_values($first), ...array_values($second), ...array_values($third)]))
        ->toBe(array_fill(0, 9, 200))
        ->and($stats['connections'])->toBe(2)
        ->and($stats['openConnections'])->toBe(1);
});

it('recovers transparently when the server closes the idle connection between two jobs (no request lost)', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'server-closes');

    $failures = 0;
    for ($job = 0; $job < 5; $job++) {
        foreach ($transport->send(Batch::requests($server->baseUrl(), 4), $profile) as $exchange) {
            $failures += $exchange->response?->status === 200 ? 0 : 1;
        }
        $server->closeSessions(); // e.g. the provider drops idle connections
        RuntimeProbe::settle($server, 'openConnections', 0);
    }

    $stats = $server->stats();
    expect($failures)->toBe(0)
        ->and($stats['received'])->toBe(20)
        ->and($stats['processed'])->toBe(20)
        ->and($stats['connections'])->toBe(5); // one reconnect per closed connection, never more
});

it('soak: 20,000 sends on one pool keep one connection and flat memory and descriptors', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);

    $run = liveForADay($transport, $server, batches: 20_000, perBatch: 5, warmUp: 1000, pauseEvery: 2000, pauseMs: 200);

    $stats = $server->stats();
    expect($run['failures'])->toBe(0)
        ->and($stats['received'])->toBe(100_000)
        ->and($stats['connections'])->toBe(1)
        ->and($run['memEnd'] - $run['memWarm'])->toBeLessThan(256 * 1024);
    if ($run['fdWarm'] !== null) {
        expect($run['fdEnd'])->toBe($run['fdWarm']);
    }
})->group('slow');
