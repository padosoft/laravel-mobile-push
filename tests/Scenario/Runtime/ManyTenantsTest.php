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
    $socketsBefore = RuntimeProbe::openSockets();

    $first = serveTenants($transport, $server, 200, $limit, checkEverySend: true);
    $memAfterFirst = RuntimeProbe::memory();
    $fdAfterFirst = RuntimeProbe::openDescriptors();
    $rssAfterFirst = RuntimeProbe::rss();
    $second = serveTenants($transport, $server, 200, $limit, checkEverySend: true);
    $memGrowth = RuntimeProbe::memory() - $memAfterFirst; // before reading the (large) server stats into memory
    $rssAfterSecond = RuntimeProbe::rss();

    $stats = RuntimeProbe::settle($server, 'openConnections', $limit);
    expect($first['failures'] + $second['failures'])->toBe(0)
        ->and($first['maxOpen'])->toBe($limit)
        ->and($second['maxOpen'])->toBe($limit)
        ->and($registry->count())->toBe($limit)
        ->and($registry->evictions())->toBe(400 - $limit)
        ->and($stats['received'])->toBe(800)
        ->and($stats['connections'])->toBe(400)          // LRU over a cycle longer than the limit: every revisit reconnects
        ->and($stats['openConnections'])->toBe($limit)
        ->and($memGrowth)->toBeLessThan(256 * 1024);
    if ($fdAfterFirst !== null) {
        expect(RuntimeProbe::openDescriptors())->toBe($fdAfterFirst)
            ->and(RuntimeProbe::openSockets() - $socketsBefore)->toBe($limit); // one socket per pool kept, nothing else
    }

    $transport->close();
    expect(RuntimeProbe::settle($server, 'openConnections', 0)['openConnections'])->toBe(0);

    RuntimeProbe::assertRssGrowthBelow($rssAfterFirst, $rssAfterSecond, 4 * 1024 * 1024, 'on the second pass over 200 tenants');
    RuntimeProbe::assertRssGrowthBelow($rssAfterFirst, RuntimeProbe::rss(), 4 * 1024 * 1024, 'after the drain');
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
    $rssWarm = null; // taken after six passes: the allocator needs that many to reach its plateau (see the note below)
    $failures = 0;
    $maxOpen = 0;
    for ($pass = 0; $pass < 9; $pass++) {
        $run = serveTenants($transport, $server, 200, $limit, checkEverySend: $pass % 3 === 0);
        $failures += $run['failures'];
        $maxOpen = max($maxOpen, $run['maxOpen']);
        if ($pass === 4) {
            $rssWarm = RuntimeProbe::rss();
        }
    }
    $memGrowth = RuntimeProbe::memory() - $mem;
    $fdEnd = RuntimeProbe::openDescriptors();
    $rssEnd = RuntimeProbe::rss();

    $stats = RuntimeProbe::settle($server, 'openConnections', $limit);
    expect($failures)->toBe(0)
        ->and($maxOpen)->toBeLessThanOrEqual($limit)
        ->and($stats['received'])->toBe(4000)
        ->and($stats['openConnections'])->toBe($limit)
        ->and($memGrowth)->toBeLessThan(256 * 1024);
    if ($fd !== null) {
        expect($fdEnd)->toBe($fd);
    }
    $transport->close();
    RuntimeProbe::settle($server, 'openConnections', 0);

    RuntimeProbe::assertRssGrowthBelow($rssWarm, $rssEnd, 1024 * 1024, 'over nine more passes over 200 tenants');
    RuntimeProbe::assertRssGrowthBelow($rssWarm, RuntimeProbe::rss(), 2 * 1024 * 1024, 'after the drain');
})->group('slow');

/*
 * Note on RSS (Linux, PHP 8.3, VmRSS, three runs each; the numbers are growth in KiB).
 * - Two passes over 200 tenants: the second pass grew RSS by 1,050-1,070; after the drain it was 600-650 above the
 *   first-pass reading. The allocator is still warming up (one pass is not a plateau), so the bound is 4 MiB.
 * - Soak: a first attempt that took the warm-up reading after ONE pass saw +22 MiB over nine more passes, with the
 *   PHP heap flat. A separate script that repeated the passes showed the cause: RSS climbs for about five passes
 *   (curl/OpenSSL allocations, ~4.7 MiB per pass) and then stays at the same value for the rest of the run, i.e. a
 *   plateau, not a leak. The soak therefore reads its warm-up value after six passes: the four passes that follow
 *   grew by 60-156, and the drain by 296-392. Bounds: 1 MiB for the run, 2 MiB for the drain (6-10x the noise).
 */
