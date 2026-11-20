<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: a campaign pushes a very large batch through one pool. Everything must travel on ONE HTTP/2
 * connection, every request must come back exactly once, memory must not grow with the batch size and
 * the transport must never hold more streams open than its window, as counted by the server itself.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['recordLimit' => 0]);
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

$runBatch = function (MockServer $server, int $count, int $window): array {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'campaign', ['window' => $window]);

    // One preallocated byte per request id: duplicate detection that does not itself grow memory.
    $seen = str_repeat("\0", $count);
    $distinct = 0;
    $duplicates = 0;
    $ok = 0;
    $memoryAfterWarmup = null;
    $i = 0;
    foreach ($transport->send(Batch::requests($server->baseUrl(), $count), $profile) as $id => $exchange) {
        $index = (int) substr((string) $id, 1);
        if ($seen[$index] === "\1") {
            $duplicates++;
        } else {
            $seen[$index] = "\1";
            $distinct++;
        }
        if ($exchange->hasResponse() && $exchange->response?->status === 200) {
            $ok++;
        }
        if (++$i === 1000) {
            gc_collect_cycles();
            $memoryAfterWarmup = memory_get_usage();
        }
    }
    gc_collect_cycles();
    $growth = memory_get_usage() - (int) $memoryAfterWarmup;
    $transport->close();

    return ['seen' => $distinct, 'duplicates' => $duplicates, 'ok' => $ok, 'growth' => $growth];
};

it('sends 20,000 requests over exactly one connection with one exchange each and flat memory', function () use (&$server, $runBatch): void {
    $result = $runBatch($server, 20_000, 100);
    $stats = $server->stats();

    expect($result['seen'])->toBe(20_000)
        ->and($result['duplicates'])->toBe(0)
        ->and($result['ok'])->toBe(20_000)
        ->and($result['growth'])->toBeLessThan(512 * 1024)
        ->and($stats['connections'])->toBe(1)
        ->and($stats['sessions'])->toBe(1)
        ->and($stats['received'])->toBe(20_000)
        ->and($stats['processed'])->toBe(20_000)
        ->and($stats['maxConcurrentStreams'])->toBeLessThanOrEqual(100)
        ->and($stats['maxConcurrentStreams'])->toBeGreaterThan(10); // it really multiplexed
});

it('never holds more streams in flight than a small window', function () use (&$server, $runBatch): void {
    $result = $runBatch($server, 2_000, 7);
    $stats = $server->stats();

    expect($result['ok'])->toBe(2_000)
        ->and($stats['connections'])->toBe(1)
        ->and($stats['maxConcurrentStreams'])->toBeLessThanOrEqual(7)
        ->and($stats['maxConcurrentStreams'])->toBeGreaterThanOrEqual(2);
});

it('respects a peer limit lower than the window without opening more connections', function () use (&$server, $runBatch): void {
    $server->settings(['maxConcurrentStreams' => 5]);
    $result = $runBatch($server, 1_000, 100);
    $stats = $server->stats();
    $server->settings(['maxConcurrentStreams' => 1000]);

    expect($result['ok'])->toBe(1_000)
        ->and($stats['connections'])->toBe(1)
        ->and($stats['maxConcurrentStreams'])->toBeLessThanOrEqual(5);
});

it('pulls the input lazily, only as the window has room', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $pulled = 0;
    $input = (function () use (&$pulled, $server): Generator {
        foreach (Batch::requests($server->baseUrl(), 1_000) as $request) {
            $pulled++;
            yield $request;
        }
    })();

    $generator = $transport->send($input, Batch::profile($server, 'lazy', ['window' => 10]));
    $generator->current(); // first exchange

    // the pilot request plus at most one window (and the one in hand), never the batch
    expect($pulled)->toBeLessThanOrEqual(12);

    // iterator_count() counts from the current element on: the one already in hand plus the 999 others.
    expect(iterator_count($generator))->toBe(1_000)->and($pulled)->toBe(1_000);
});

it('sends 100,000 requests over one connection with flat memory', function () use (&$server, $runBatch): void {
    $result = $runBatch($server, 100_000, 100);
    $stats = $server->stats();

    expect($result['seen'])->toBe(100_000)
        ->and($result['ok'])->toBe(100_000)
        ->and($result['growth'])->toBeLessThan(512 * 1024)
        ->and($stats['connections'])->toBe(1)
        ->and($stats['processed'])->toBe(100_000)
        ->and($stats['maxConcurrentStreams'])->toBeLessThanOrEqual(100);
})->group('slow');
