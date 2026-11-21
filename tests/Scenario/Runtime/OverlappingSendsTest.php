<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story (the Octane/Swoole concern, simulated with PHP Fibers): two requests served by the same process push through
 * the same credential at the same time. Each one iterates its own Transport::send() generator on the SAME pool and
 * yields control to the other after every answer, so the two generators overlap in time on one curl multi handle.
 * Every answer must reach the generator that sent the request, nothing may be lost or delivered twice, and a
 * generator abandoned half-way must not damage the other one.
 *
 * What this does NOT simulate: a coroutine runtime that switches INSIDE a curl call (Swoole's curl hooks). That
 * cannot be reproduced without Swoole; see docs/runtimes.md.
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

/**
 * A "request" of the simulated runtime: iterate one send() and hand control back after every exchange.
 * The fiber returns every (id, exchange) pair it received, in order.
 *
 * @param  iterable<Request>  $requests
 */
function coroutineSend(CurlTransport $transport, iterable $requests, ConnectionProfile $profile, ?int $stopAfter = null): Fiber
{
    return new Fiber(function () use ($transport, $requests, $profile, $stopAfter): array {
        $received = [];
        foreach ($transport->send($requests, $profile) as $id => $exchange) {
            $received[] = [$id, $exchange];
            if ($stopAfter !== null && count($received) >= $stopAfter) {
                break; // the request is abandoned half-way (client went away, exception, timeout)
            }
            Fiber::suspend();
        }

        return $received;
    });
}

/** Round-robin scheduler: resume each live fiber in turn until all are done. */
function runInterleaved(Fiber ...$fibers): void
{
    foreach ($fibers as $fiber) {
        $fiber->start();
    }
    do {
        $alive = false;
        foreach ($fibers as $fiber) {
            if (! $fiber->isTerminated()) {
                $fiber->resume();
                $alive = $alive || ! $fiber->isTerminated();
            }
        }
    } while ($alive);
}

/** @param  list<array{0: string, 1: Exchange}>  $pairs @return Generator<string, Exchange> */
function asExchanges(array $pairs): Generator
{
    foreach ($pairs as [$id, $exchange]) {
        yield $id => $exchange;
    }
}

it('routes every answer to the generator that sent it when two sends on one pool are interleaved', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'shared-pool', ['window' => 8]);
    $server->rules(
        ...RuntimeProbe::tagRules(['alice'], 40, delayMs: 15),
        ...RuntimeProbe::tagRules(['bob'], 40, delayMs: 5),
    );
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 1), $profile)); // warm: protocol known

    $alice = coroutineSend($transport, RuntimeProbe::tagged($server->baseUrl(), 'alice', 40), $profile);
    $bob = coroutineSend($transport, RuntimeProbe::tagged($server->baseUrl(), 'bob', 40), $profile);
    runInterleaved($alice, $bob);

    $requests = $server->requests();
    $stats = $server->stats();
    // In the order the server received them, the two senders' requests alternate many times: they overlapped.
    $order = array_map(fn (array $r): string => explode('/', $r['path'])[1], array_slice($requests, 1));
    $switches = 0;
    for ($i = 1; $i < count($order); $i++) {
        $switches += $order[$i] !== $order[$i - 1] ? 1 : 0;
    }
    expect(RuntimeProbe::problems(asExchanges($alice->getReturn()), 'alice', 40))->toBe([])
        ->and(RuntimeProbe::problems(asExchanges($bob->getReturn()), 'bob', 40))->toBe([])
        ->and($stats['received'])->toBe(81)
        ->and($stats['connections'])->toBe(1)
        ->and($switches)->toBeGreaterThan(2)
        ->and(array_unique(array_column($requests, 'session')))->toHaveCount(1);
});

it('an abandoned generator does not corrupt the other one; its cancelled streams are reset, the connection survives', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'abandoned', ['window' => 10]);
    $server->rules(...[
        ...RuntimeProbe::tagRules(['keeper'], 30, delayMs: 20),
        ...RuntimeProbe::tagRules(['quitter'], 3, delayMs: 30),
        // still in flight when the quitter walks away ("hang" would hide the reset from the server's counter)
        ['path' => '^/quitter/', 'action' => 'respond', 'status' => 200, 'delayMs' => 3000],
        ...RuntimeProbe::tagRules(['later'], 5),
    ]);
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 1), $profile));

    $keeper = coroutineSend($transport, RuntimeProbe::tagged($server->baseUrl(), 'keeper', 30), $profile);
    $quitter = coroutineSend($transport, RuntimeProbe::tagged($server->baseUrl(), 'quitter', 30), $profile, stopAfter: 3);
    runInterleaved($keeper, $quitter);
    $later = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'later', 5), $profile));

    $quitterPairs = $quitter->getReturn();
    $stats = $server->waitFor(fn (array $s): bool => $s['clientResets'] > 0);
    expect(RuntimeProbe::problems(asExchanges($keeper->getReturn()), 'keeper', 30))->toBe([])
        ->and($quitterPairs)->toHaveCount(3)
        ->and(array_filter($quitterPairs, fn (array $p): bool => ! str_starts_with($p[0], 'quitter-') || $p[1]->response?->body !== substr($p[0], 0, 7).'/'.substr($p[0], 8)))->toBe([])
        ->and(RuntimeProbe::problems($later, 'later', 5))->toBe([])
        ->and($stats['clientResets'])->toBeGreaterThan(0)    // the quitter's in-flight streams were cancelled
        ->and($stats['connections'])->toBe(1);
});

it('a request suspended forever keeps its pool busy until its fiber is destroyed; then the pool can be closed', function () use (&$server): void {
    $registry = new CurlPoolRegistry;
    $transport = new CurlTransport($registry);
    $profile = Batch::profile($server, 'stuck', ['window' => 5]);
    $server->rules(...RuntimeProbe::tagRules(['stuck', 'other'], 10, delayMs: 10));
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 1), $profile));

    $stuck = coroutineSend($transport, RuntimeProbe::tagged($server->baseUrl(), 'stuck', 10), $profile);
    $stuck->start();
    $stuck->resume(); // two answers taken, three still in flight; never resumed again
    $other = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'other', 10), $profile));

    $transport->close('stuck'); // in use: detached now, closed when its last user releases it
    $whileStuck = $server->stats()['openConnections'];
    unset($stuck);              // destroying the suspended fiber runs the generator's cleanup
    gc_collect_cycles();

    expect(RuntimeProbe::problems($other, 'other', 10))->toBe([])
        ->and($whileStuck)->toBe(1)
        ->and(RuntimeProbe::settle($server, 'openConnections', 0)['openConnections'])->toBe(0)
        ->and($registry->count())->toBe(0);
});

it('a generator waiting for its answer does not busy-spin while another suspended generator has answers queued', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'no-spin', ['window' => 5]);
    $server->rules(
        ['path' => '^/waiter/', 'action' => 'respond', 'status' => 200, 'body' => 'w', 'delayMs' => 400],
        ['path' => '^/sleeper/0$', 'action' => 'respond', 'status' => 200, 'body' => 's', 'delayMs' => 5],
        ['path' => '^/sleeper/', 'action' => 'respond', 'status' => 200, 'body' => 's', 'delayMs' => 60],
    );
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 1), $profile));

    // The sleeper takes its first answer and is suspended; its four other answers arrive while the waiter waits.
    $sleeper = $transport->send(RuntimeProbe::tagged($server->baseUrl(), 'sleeper', 5), $profile);
    $sleeper->current();
    $cpu = RuntimeProbe::cpuSeconds();
    $wall = microtime(true);
    $waiter = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'waiter', 1), $profile));
    $wall = microtime(true) - $wall;
    $cpu = RuntimeProbe::cpuSeconds() - $cpu;
    $rest = iterator_to_array($sleeper);

    expect($waiter['waiter-0']->response?->body)->toBe('w')
        ->and($rest)->toHaveCount(5)
        ->and($wall)->toBeGreaterThan(0.3)
        // Before the fix the waiter spun on curl_multi_exec for the whole wait (measured ~100% of one core).
        ->and($cpu)->toBeLessThan(0.04);
});
