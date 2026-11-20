<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: two tenants push to the same provider host. Their credentials differ, so their pool keys differ,
 * and they must never share a connection; each tenant's connection must however be reused across send()
 * calls, until it is closed on purpose or evicted because the process holds too many pools.
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

it('never shares a connection between two pool keys, even to the same host', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);

    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 20), Batch::profile($server, 'tenant-a')));
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 20), Batch::profile($server, 'tenant-b')));

    $sessions = array_unique(array_column($server->requests(), 'session'));
    expect($server->stats()['connections'])->toBe(2)
        ->and($sessions)->toHaveCount(2);
});

it('reuses the same connection across send() calls with the same pool key', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'tenant-a');

    foreach (range(1, 5) as $_) {
        Batch::collect($transport->send(Batch::requests($server->baseUrl(), 10), $profile));
    }

    expect($server->stats()['connections'])->toBe(1)
        ->and($server->stats()['received'])->toBe(50);
});

it('shares the process registry between transport instances', function () use (&$server): void {
    $profile = Batch::profile($server, 'process-wide-'.bin2hex(random_bytes(4)));

    Batch::collect((new CurlTransport)->send(Batch::requests($server->baseUrl(), 3), $profile));
    Batch::collect((new CurlTransport)->send(Batch::requests($server->baseUrl(), 3), $profile));
    (new CurlTransport)->close($profile->poolKey);

    expect($server->stats()['connections'])->toBe(1);
});

it('opens a new connection after close($poolKey), and close() releases the old one', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'tenant-a');

    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 5), $profile));
    $transport->close('tenant-a');
    $afterClose = $server->waitFor(fn (array $s): bool => $s['openConnections'] === 0);
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 5), $profile));

    expect($afterClose['openConnections'])->toBe(0)
        ->and($server->stats()['connections'])->toBe(2)
        ->and($server->stats()['openConnections'])->toBe(1);
});

it('evicts and closes the least recently used pool when the registry is full', function () use (&$server): void {
    $registry = new CurlPoolRegistry(maxPools: 3);
    $transport = new CurlTransport($registry);

    foreach (['k1', 'k2', 'k3'] as $key) {
        Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), Batch::profile($server, $key)));
    }
    // touch k1 so that k2 becomes the least recently used
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), Batch::profile($server, 'k1')));
    expect($server->stats()['openConnections'])->toBe(3);

    foreach (['k4', 'k5', 'k6', 'k7', 'k8'] as $key) {
        Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), Batch::profile($server, $key)));
    }

    $stats = $server->waitFor(fn (array $s): bool => $s['openConnections'] <= 3);
    expect($registry->count())->toBe(3)
        ->and($registry->keys())->toBe(['k6', 'k7', 'k8'])
        ->and($registry->evictions())->toBe(5)
        ->and($stats['connections'])->toBe(8)
        ->and($stats['openConnections'])->toBe(3); // evicted pools really closed their connections

    // k2 was evicted first (LRU): using it again needs a new connection
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), Batch::profile($server, 'k2')));
    expect($server->stats()['connections'])->toBe(9);
});

it('expires a pool idle for longer than maxIdleSeconds', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'short-idle', ['maxIdleSeconds' => 1]);

    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), $profile));
    usleep(1_100_000);
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 2), $profile));

    $stats = $server->waitFor(fn (array $s): bool => $s['openConnections'] === 1);
    expect($stats['connections'])->toBe(2)->and($stats['openConnections'])->toBe(1);
});

it('cleans up when the consumer stops after the first exchange, and the pool keeps working', function () use (&$server): void {
    $server->rules(['path' => '^/r/(?!0$)', 'action' => 'respond', 'delayMs' => 200]);
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'early-exit', ['window' => 20]);

    foreach ($transport->send(Batch::requests($server->baseUrl(), 1_000), $profile) as $id => $exchange) {
        expect($exchange->response?->status)->toBe(200);
        break;
    }
    gc_collect_cycles();
    $server->rules();

    // the in-flight streams were cancelled; the connection survives and a new send() reuses it
    $byId = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 30), $profile));
    $stats = $server->stats();

    expect($byId)->toHaveCount(30)
        ->and(array_filter($byId, fn (array $e): bool => $e[0]->response?->status === 200))->toHaveCount(30)
        ->and($stats['connections'])->toBe(1)
        ->and($stats['received'])->toBeLessThanOrEqual(1 + 20 + 30) // never more than one window was pulled
        ->and($stats['clientResets'])->toBeGreaterThanOrEqual(1);
});

it('lets a second send() run on the same pool while the first is being consumed', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'nested', ['window' => 5]);

    $outer = 0;
    $inner = 0;
    foreach ($transport->send(Batch::requests($server->baseUrl(), 20), $profile) as $id => $exchange) {
        $outer++;
        if ($outer === 3) {
            foreach ($transport->send(Batch::requests($server->baseUrl().'/nested', 7), $profile) as $nested) {
                expect($nested->response?->status)->toBe(200);
                $inner++;
            }
        }
    }

    expect($outer)->toBe(20)->and($inner)->toBe(7)
        ->and($server->stats()['received'])->toBe(27)
        ->and($server->stats()['connections'])->toBe(1);
});

it('does not reuse a pool inherited across fork() in the child', function () use (&$server): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required');
    }
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'forked');
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));

    $pid = pcntl_fork();
    if ($pid === 0) {
        $ok = 0;
        foreach ($transport->send(Batch::requests($server->baseUrl().'/child', 3), $profile) as $exchange) {
            $ok += $exchange->response?->status === 200 ? 1 : 0;
        }
        // Leave without running PHP shutdown: see docs/transport.md (fork safety, residual risk).
        posix_kill(getmypid(), SIGKILL);
    }
    pcntl_waitpid($pid, $status);

    $childSessions = array_unique(array_column(array_filter($server->requests(), fn (array $r): bool => str_starts_with($r['path'], '/child')), 'session'));
    $parentSessions = array_unique(array_column(array_filter($server->requests(), fn (array $r): bool => ! str_starts_with($r['path'], '/child')), 'session'));

    // the parent keeps using its own connection afterwards
    $after = Batch::collect($transport->send(Batch::requests($server->baseUrl().'/parent-again', 3), $profile));

    expect($childSessions)->toHaveCount(1)
        ->and(array_intersect($childSessions, $parentSessions))->toBe([])
        ->and(array_filter($after, fn (array $e): bool => $e[0]->response?->status === 200))->toHaveCount(3)
        ->and($server->stats()['connections'])->toBe(2);
});
