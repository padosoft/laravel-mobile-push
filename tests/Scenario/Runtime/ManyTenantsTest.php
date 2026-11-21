<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: one worker serves two hundred tenants, each with its own credential (its own pool key), one after another,
 * and then serves them all again. The process must never hold more connections than the registry limit (counted by
 * the server: TCP connections currently open), every evicted pool must be closed cleanly (the server sees the close,
 * the process does not leak descriptors), and memory must not grow with the number of tenants seen.
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
 * One pass over every tenant; returns the highest number of open connections the server reported after each send
 * once closes had settled, and the failures.
 *
 * @return array{maxOpen: int, failures: int}
 */
function serveTenants(CurlTransport $transport, MockServer $server, int $tenants, int $limit, bool $checkEverySend): array
{
    $maxOpen = 0;
    $failures = 0;
    for ($t = 0; $t < $tenants; $t++) {
        $profile = Batch::profile($server, sprintf('apns|team:T%03d|key:K%03d|production|gen:1', $t, $t));
        foreach ($transport->send(Batch::requests($server->baseUrl(), 2), $profile) as $exchange) {
            $failures += $exchange->response?->status === 200 ? 0 : 1;
        }
        if ($checkEverySend) {
            // Closing is asynchronous on the server side: wait until it is at or under the limit, then record.
            $stats = $server->waitFor(static fn (array $s): bool => $s['openConnections'] <= $limit, 2000);
            $maxOpen = max($maxOpen, (int) $stats['openConnections']);
        }
    }

    return ['maxOpen' => $maxOpen, 'failures' => $failures];
}

it('never holds more connections than the registry limit while 200 tenants are served and revisited', function () use (&$server): void {
    $registry = new CurlPoolRegistry; // default limit (32)
    $transport = new CurlTransport($registry);
    $limit = $registry->maxPools();

    $first = serveTenants($transport, $server, 200, $limit, checkEverySend: true);
    $memAfterFirst = RuntimeProbe::memory();
    $fdAfterFirst = RuntimeProbe::openDescriptors();
    $second = serveTenants($transport, $server, 200, $limit, checkEverySend: true);

    $stats = RuntimeProbe::settle($server, 'openConnections', $limit);
    expect($first['failures'] + $second['failures'])->toBe(0)
        ->and($first['maxOpen'])->toBe($limit)
        ->and($second['maxOpen'])->toBe($limit)
        ->and($registry->count())->toBe($limit)
        ->and($registry->evictions())->toBe(400 - $limit)
        ->and($stats['received'])->toBe(800)
        ->and($stats['connections'])->toBe(400)          // LRU over a cycle longer than the limit: every revisit reconnects
        ->and($stats['openConnections'])->toBe($limit)
        ->and(RuntimeProbe::memory() - $memAfterFirst)->toBeLessThan(256 * 1024);
    if ($fdAfterFirst !== null) {
        expect(RuntimeProbe::openDescriptors())->toBe($fdAfterFirst)
            ->and(RuntimeProbe::openSockets())->toBeLessThanOrEqual($limit);
    }

    $transport->close();
    expect(RuntimeProbe::settle($server, 'openConnections', 0)['openConnections'])->toBe(0);
});

it('keeps the connections of the tenants that fit under the limit: revisiting a small working set reuses them', function () use (&$server): void {
    $registry = new CurlPoolRegistry(8);
    $transport = new CurlTransport($registry);

    serveTenants($transport, $server, 8, 8, checkEverySend: false);
    serveTenants($transport, $server, 8, 8, checkEverySend: false);
    serveTenants($transport, $server, 8, 8, checkEverySend: false);

    $stats = $server->stats();
    expect($stats['connections'])->toBe(8)
        ->and($stats['received'])->toBe(48)
        ->and($registry->evictions())->toBe(0);
});

it('soak: 200 tenants served ten times stay within the limit with flat memory and descriptors', function () use (&$server): void {
    $registry = new CurlPoolRegistry;
    $transport = new CurlTransport($registry);
    $limit = $registry->maxPools();

    serveTenants($transport, $server, 200, $limit, checkEverySend: false);
    $mem = RuntimeProbe::memory();
    $fd = RuntimeProbe::openDescriptors();
    $failures = 0;
    $maxOpen = 0;
    for ($pass = 0; $pass < 9; $pass++) {
        $run = serveTenants($transport, $server, 200, $limit, checkEverySend: $pass % 3 === 0);
        $failures += $run['failures'];
        $maxOpen = max($maxOpen, $run['maxOpen']);
    }

    $stats = RuntimeProbe::settle($server, 'openConnections', $limit);
    expect($failures)->toBe(0)
        ->and($maxOpen)->toBeLessThanOrEqual($limit)
        ->and($stats['received'])->toBe(4000)
        ->and($stats['openConnections'])->toBe($limit)
        ->and(RuntimeProbe::memory() - $mem)->toBeLessThan(256 * 1024);
    if ($fd !== null) {
        expect(RuntimeProbe::openDescriptors())->toBe($fd);
    }
    $transport->close();
})->group('slow');
