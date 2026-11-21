<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: a process that already holds a pooled connection forks (pcntl_fork, spatie/fork, Laravel's Concurrency
 * "fork" driver). The child inherits the parent's sockets and TLS state.
 *
 *  (a) While the child is alive, both processes send at the same time: the registry resets for the child's PID, so
 *      the child opens its own connection, never writes on the inherited one, and every process receives exactly
 *      the answers to its own requests.
 *  (b) The residual risk: when the child EXITS NORMALLY, PHP's shutdown frees the inherited curl handles and libcurl
 *      closes them (SSL_read + close_notify on the socket the parent still uses). Measured here: an idle parent
 *      connection is closed (the parent reconnects transparently on its next send); requests the parent has in
 *      flight at that moment were processed by the server but come back Unknown. The transport cannot prevent it
 *      (see docs/runtimes.md): the safe patterns are pinned below.
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
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required');
    }
    $server->reset();
});

/**
 * Fork a child that runs $child and reports its result (JSON) through a socket pair. $exit: 'kill' ends the child
 * with SIGKILL (no PHP shutdown), 'normal' with exit(0) (PHP shutdown runs and frees the inherited handles).
 *
 * @param  Closure(): array<mixed>  $child
 * @return array{pid: int, socket: resource}
 */
function forkChild(Closure $child, string $exit): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('socket pair');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        try {
            $result = $child();
        } catch (Throwable $e) {
            $result = ['error' => $e::class.': '.$e->getMessage()];
        }
        fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR));
        fclose($pair[1]);
        if ($exit === 'normal') {
            exit(0);
        }
        posix_kill(getmypid(), SIGKILL);
    }
    fclose($pair[1]);

    return ['pid' => $pid, 'socket' => $pair[0]];
}

/** @param  array{pid: int, socket: resource}  $child @return array<mixed> */
function joinChild(array $child): array
{
    $raw = stream_get_contents($child['socket']);
    fclose($child['socket']);
    pcntl_waitpid($child['pid'], $status);

    return is_string($raw) && $raw !== '' ? (array) json_decode($raw, true) : ['error' => 'no result from the child'];
}

it('(a) parent and forked child send at the same time: own connections, own answers, nothing lost', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'forked-a', ['window' => 10]);
    $server->rules(...RuntimeProbe::tagRules(['warm', 'child', 'parent', 'after'], 30, delayMs: 20));

    // The parent's pool is open and idle when it forks.
    $warm = Batch::collect($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'warm', 5), $profile));

    $child = forkChild(function () use ($transport, $profile, $server): array {
        $exchanges = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'child', 30), $profile));

        return ['problems' => RuntimeProbe::problems($exchanges, 'child', 30), 'inherited' => CurlPoolRegistry::inheritedCount(), 'pid' => getmypid()];
    }, exit: 'kill');

    // Meanwhile the parent keeps sending on its own pool.
    $parent = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'parent', 30), $profile));
    $fromChild = joinChild($child);
    $after = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'after', 30), $profile));

    $requests = $server->requests();
    $sessionsOf = fn (string $tag): array => array_values(array_unique(array_column(array_filter($requests, fn (array $r): bool => str_starts_with($r['path'], '/'.$tag.'/')), 'session')));
    $stats = $server->stats();

    expect($warm)->toHaveCount(5)
        ->and($fromChild['problems'] ?? $fromChild)->toBe([])
        ->and($fromChild['inherited'])->toBe(1)               // the child abandoned (did not reuse) the parent's pool
        ->and(RuntimeProbe::problems($parent, 'parent', 30))->toBe([])
        ->and(RuntimeProbe::problems($after, 'after', 30))->toBe([])
        ->and($sessionsOf('child'))->toHaveCount(1)
        ->and(array_intersect($sessionsOf('child'), [...$sessionsOf('warm'), ...$sessionsOf('parent'), ...$sessionsOf('after')]))->toBe([])
        ->and([...$sessionsOf('warm'), ...$sessionsOf('parent'), ...$sessionsOf('after')])->toBe(array_fill(0, 3, $sessionsOf('warm')[0]))
        ->and($stats['connections'])->toBe(2)                 // parent: one for the whole story; child: its own
        ->and($stats['received'])->toBe(95)
        ->and($stats['processed'])->toBe(95);
});

it('(b) a child that exits normally closes the idle connection it inherited: the parent reconnects, nothing is lost', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'forked-b-idle');
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));

    joinChild(forkChild(fn (): array => ['ok' => true], exit: 'normal')); // the child never touches the transport
    $closedByChild = RuntimeProbe::settle($server, 'openConnections', 0);
    $after = Batch::collect($transport->send(Batch::requests($server->baseUrl().'/after', 3), $profile));

    expect($closedByChild['openConnections'])->toBe(0)       // the parent's connection is gone
        ->and(array_map(fn (array $e): ?int => $e[0]->response?->status, array_values($after)))->toBe([200, 200, 200])
        ->and($server->stats()['connections'])->toBe(2);    // the documented reconnect
});

it('(b) a child that exits normally while the parent has requests in flight turns them into Unknown, although the server processed them', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'forked-b-inflight');
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));
    $server->rules(['path' => '^/slow/', 'action' => 'respond', 'status' => 200, 'delayMs' => 600]);

    $child = forkChild(function (): array {
        usleep(200_000); // exits while the parent waits for its answers

        return ['ok' => true];
    }, exit: 'normal');
    $inflight = Batch::collect($transport->send(Batch::requests($server->baseUrl().'/slow', 3), $profile));
    joinChild($child);
    $next = Batch::collect($transport->send(Batch::requests($server->baseUrl().'/next', 3), $profile));

    $evidence = array_map(fn (array $e): ?TransportEvidence => $e[0]->failure?->evidence, array_values($inflight));
    $stats = $server->stats();
    expect($evidence)->toBe([TransportEvidence::Unknown, TransportEvidence::Unknown, TransportEvidence::Unknown])
        ->and(count(array_filter($server->requests(), fn (array $r): bool => str_starts_with($r['path'], '/slow/') && $r['processed'])))->toBe(3)
        ->and(array_map(fn (array $e): ?int => $e[0]->response?->status, array_values($next)))->toBe([200, 200, 200])
        ->and($stats['connections'])->toBe(2);
});

it('(b) safe pattern 1: closing the pools before forking leaves nothing for the child to damage', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'forked-closed-first');
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));
    $server->rules(['path' => '^/slow/', 'action' => 'respond', 'status' => 200, 'delayMs' => 600]);

    $transport->close(); // MobilePush::flush() does this for every cached connection
    $child = forkChild(function (): array {
        usleep(200_000);

        return ['ok' => true];
    }, exit: 'normal');
    $inflight = Batch::collect($transport->send(Batch::requests($server->baseUrl().'/slow', 3), $profile));
    joinChild($child);

    expect(array_map(fn (array $e): ?int => $e[0]->response?->status, array_values($inflight)))->toBe([200, 200, 200])
        ->and($server->stats()['connections'])->toBe(2); // the closed one + the one opened after the fork
});

it('(b) safe pattern 2: a child that ends with SIGKILL after its work does not touch the parent connection', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry);
    $profile = Batch::profile($server, 'forked-killed');
    Batch::collect($transport->send(Batch::requests($server->baseUrl(), 3), $profile));
    $server->rules(['path' => '^/slow/', 'action' => 'respond', 'status' => 200, 'delayMs' => 600]);

    $child = forkChild(function (): array {
        usleep(200_000);

        return ['ok' => true];
    }, exit: 'kill');
    $inflight = Batch::collect($transport->send(Batch::requests($server->baseUrl().'/slow', 3), $profile));
    joinChild($child);

    expect(array_map(fn (array $e): ?int => $e[0]->response?->status, array_values($inflight)))->toBe([200, 200, 200])
        ->and($server->stats()['connections'])->toBe(1);
});
