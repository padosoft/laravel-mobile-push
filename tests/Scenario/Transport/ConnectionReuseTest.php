<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\ConnectionReuse;
use Padosoft\MobilePush\Core\Transport\Curl\CoroutineCurlHook;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\Batch;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: a runtime where sharing one connection pool between concurrent callers is not safe (Swoole with its curl
 * coroutine hooks: a second coroutine can drive the same curl_multi handle while the first is suspended INSIDE
 * curl_multi_exec). With connection reuse off every send() opens its own pool and closes it when it ends, so two
 * callers can never touch the same handle. The price is one connection (TCP + TLS handshake) per send, which the
 * mock server counts. `auto` turns reuse off only for sends that start while a curl hook is active.
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

/** The facts of a coroutine runtime that hooks curl (Swoole 6, SWOOLE_HOOK_ALL), or of a plain process. */
function reuseHook(bool $curlHooked): CoroutineCurlHook
{
    return new CoroutineCurlHook(
        static fn (string $extension): bool => $curlHooked && $extension === 'swoole',
        static fn (string $class): ?int => $class === 'Swoole\Runtime' ? 0x7FFFFFFF & ~2048 : null,
        static fn (string $name): ?int => null,
    );
}

/** A simulated request coroutine: iterate one send() and yield control after every exchange. */
function reuseFiber(CurlTransport $transport, iterable $requests, ConnectionProfile $profile, ?int $stopAfter = null): Fiber
{
    return new Fiber(function () use ($transport, $requests, $profile, $stopAfter): array {
        $received = [];
        foreach ($transport->send($requests, $profile) as $id => $exchange) {
            $received[] = [$id, $exchange];
            if ($stopAfter !== null && count($received) >= $stopAfter) {
                break;
            }
            Fiber::suspend();
        }

        return $received;
    });
}

/** @param  list<array{0: string, 1: Exchange}>  $pairs @return Generator<string, Exchange> */
function reusePairs(array $pairs): Generator
{
    foreach ($pairs as [$id, $exchange]) {
        yield $id => $exchange;
    }
}

/** @return array<string, int> tag => number of distinct server-side sessions its requests arrived on */
function sessionsByTag(MockServer $server): array
{
    $sessions = [];
    foreach ($server->requests() as $r) {
        $sessions[explode('/', $r['path'])[1]][$r['session']] = true;
    }

    return array_map('count', $sessions);
}

it('with reuse on, five sends share one connection; with reuse off, each send opens and closes its own', function (ConnectionReuse $reuse, int $connections, int $openAfter) use (&$server): void {
    $registry = new CurlPoolRegistry;
    $transport = new CurlTransport($registry, reuse: $reuse, hook: reuseHook(false));
    $profile = Batch::profile($server, 'reuse-'.$reuse->value);

    $answers = [];
    foreach (range(1, 5) as $_) {
        $answers[] = Batch::collect($transport->send(Batch::requests($server->baseUrl(), 10), $profile));
    }
    $stats = RuntimeProbe::settle($server, 'openConnections', $openAfter);

    foreach ($answers as $byId) {
        expect(array_keys($byId))->toEqualCanonicalizing(array_map(fn (int $i): string => "r{$i}", range(0, 9)));
        foreach ($byId as $exchanges) {
            expect($exchanges)->toHaveCount(1)->and($exchanges[0]->response?->status)->toBe(200);
        }
    }
    expect($stats['received'])->toBe(50)
        ->and($stats['connections'])->toBe($connections)
        ->and($stats['openConnections'])->toBe($openAfter)
        ->and($registry->count())->toBe($reuse === ConnectionReuse::On ? 1 : 0)
        ->and($transport->privatePoolsOpened())->toBe($reuse === ConnectionReuse::On ? 0 : 5)
        ->and($transport->privatePoolsOpen())->toBe(0);
})->with([
    'on' => [ConnectionReuse::On, 1, 1],
    'off' => [ConnectionReuse::Off, 5, 0],
]);

it('auto reuses connections in a plain process and stops reusing them while a coroutine curl hook is active', function (bool $hooked, int $connections) use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Auto, hook: reuseHook($hooked));
    $profile = Batch::profile($server, 'auto-'.($hooked ? 'hooked' : 'plain'));
    $server->rules(...RuntimeProbe::tagRules(['auto'], 5));

    foreach (range(1, 4) as $_) {
        expect(RuntimeProbe::problems(iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'auto', 5), $profile)), 'auto', 5))->toBe([]);
    }

    expect($server->stats()['connections'])->toBe($connections)
        ->and($transport->privatePoolsOpened())->toBe($hooked ? 4 : 0);
})->with([
    'plain process' => [false, 1],
    'Swoole with SWOOLE_HOOK_ALL' => [true, 4],
]);

it('auto decides per send: a send started after the hook was turned on gets its own connection', function () use (&$server): void {
    $hooked = false;
    $hook = new CoroutineCurlHook(
        static fn (string $extension): bool => $extension === 'swoole',
        static function (string $class) use (&$hooked): ?int {
            return $hooked ? 4096 : 0;
        },
        static fn (string $name): ?int => null,
    );
    $registry = new CurlPoolRegistry;
    $transport = new CurlTransport($registry, hook: $hook);
    $profile = Batch::profile($server, 'auto-switch');
    $server->rules(...RuntimeProbe::tagRules(['plain', 'hooked'], 4));

    $plain = [];
    foreach (range(1, 3) as $_) {
        $plain[] = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'plain', 4), $profile));
    }
    $connectionsBefore = $server->stats()['connections'];
    $hooked = true; // e.g. Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL) later in the process
    $hookedAnswers = [];
    foreach (range(1, 3) as $_) {
        $hookedAnswers[] = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'hooked', 4), $profile));
    }
    $stats = $server->stats();

    foreach ($hookedAnswers as $answers) {
        expect(RuntimeProbe::problems($answers, 'hooked', 4))->toBe([]);
    }
    foreach ($plain as $answers) {
        expect(RuntimeProbe::problems($answers, 'plain', 4))->toBe([]);
    }
    expect($connectionsBefore)->toBe(1)
        ->and($stats['connections'])->toBe(4)         // the shared one + one per hooked send
        ->and($transport->privatePoolsOpened())->toBe(3)
        ->and($transport->privatePoolsOpen())->toBe(0)
        ->and($registry->count())->toBe(1);           // the shared pool of the plain sends, untouched by the others
});

it('auto with a coroutine curl hook: N sends open N connections on the server, every one closed afterwards', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Auto, hook: reuseHook(true));
    $profile = Batch::profile($server, 'auto-hooked');
    $server->rules(...RuntimeProbe::tagRules(['job'], 5));

    foreach (range(1, 6) as $_) {
        expect(RuntimeProbe::problems(iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'job', 5), $profile)), 'job', 5))->toBe([]);
    }
    $stats = RuntimeProbe::settle($server, 'openConnections', 0);

    expect($stats['connections'])->toBe(6)
        ->and($stats['received'])->toBe(30)
        ->and($stats['openConnections'])->toBe(0)
        ->and(count(array_unique(array_column($server->requests(), 'session'))))->toBe(6)
        ->and($transport->privatePoolsOpen())->toBe(0);
});

it('reuse off: a consumer that abandons the generator with requests in flight leaves no pool and no connection open', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Off);
    $profile = Batch::profile($server, 'off-abandon', ['window' => 10]);
    $server->rules(...[
        ...RuntimeProbe::tagRules(['quit'], 2, delayMs: 5),
        ['path' => '^/quit/', 'action' => 'respond', 'status' => 200, 'delayMs' => 3000], // still in flight
    ]);

    $taken = [];
    foreach ($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'quit', 10), $profile) as $id => $exchange) {
        $taken[$id] = $exchange;
        if (count($taken) === 2) {
            $whileRunning = $transport->privatePoolsOpen();
            break;
        }
    }
    $stats = RuntimeProbe::settle($server, 'openConnections', 0);

    expect(array_keys($taken))->toEqualCanonicalizing(['quit-0', 'quit-1'])
        ->and($whileRunning ?? null)->toBe(1)
        ->and($transport->privatePoolsOpen())->toBe(0)
        ->and($stats['openConnections'])->toBe(0)
        ->and($stats['received'])->toBe(10);
});

it('reuse off: a generator that is never iterated opens nothing', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Off);
    $generator = $transport->send(Batch::requests($server->baseUrl(), 5), Batch::profile($server, 'off-idle'));
    unset($generator);

    expect($transport->privatePoolsOpened())->toBe(0)
        ->and($transport->privatePoolsOpen())->toBe(0)
        ->and($server->stats()['connections'])->toBe(0);
});

it('reuse off: two interleaved sends of one pool key each get their own connection and only their own answers', function () use (&$server): void {
    $registry = new CurlPoolRegistry;
    $transport = new CurlTransport($registry, reuse: ConnectionReuse::Off);
    $profile = Batch::profile($server, 'off-shared-key', ['window' => 8]);
    $server->rules(
        ...RuntimeProbe::tagRules(['alice'], 40, delayMs: 15),
        ...RuntimeProbe::tagRules(['bob'], 40, delayMs: 5),
    );

    $alice = reuseFiber($transport, RuntimeProbe::tagged($server->baseUrl(), 'alice', 40), $profile);
    $bob = reuseFiber($transport, RuntimeProbe::tagged($server->baseUrl(), 'bob', 40), $profile);
    $alice->start();
    $bob->start();
    $bothRunning = $transport->privatePoolsOpen();
    do {
        $alive = false;
        foreach ([$alice, $bob] as $fiber) {
            if (! $fiber->isTerminated()) {
                $fiber->resume();
                $alive = $alive || ! $fiber->isTerminated();
            }
        }
    } while ($alive);
    $stats = RuntimeProbe::settle($server, 'openConnections', 0);
    $sessions = sessionsByTag($server);
    $sessionOf = [];
    foreach ($server->requests() as $r) {
        $sessionOf[explode('/', $r['path'])[1]] = $r['session'];
    }

    expect(RuntimeProbe::problems(reusePairs($alice->getReturn()), 'alice', 40))->toBe([])
        ->and(RuntimeProbe::problems(reusePairs($bob->getReturn()), 'bob', 40))->toBe([])
        ->and($bothRunning)->toBe(2)
        ->and($stats['received'])->toBe(80)
        ->and($stats['connections'])->toBe(2)
        ->and($sessions)->toBe(['alice' => 1, 'bob' => 1])          // each sender on one connection...
        ->and($sessionOf['alice'])->not->toBe($sessionOf['bob'])    // ...never the other's
        ->and($stats['openConnections'])->toBe(0)
        ->and($transport->privatePoolsOpen())->toBe(0)
        ->and($registry->count())->toBe(0);
});

it('reuse off: one interleaved send abandoned half-way does not disturb the other, and both pools end closed', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Off);
    $profile = Batch::profile($server, 'off-quitter', ['window' => 10]);
    $server->rules(...[
        ...RuntimeProbe::tagRules(['keeper'], 30, delayMs: 20),
        ...RuntimeProbe::tagRules(['quitter'], 3, delayMs: 30),
        ['path' => '^/quitter/', 'action' => 'respond', 'status' => 200, 'delayMs' => 3000],
    ]);

    $keeper = reuseFiber($transport, RuntimeProbe::tagged($server->baseUrl(), 'keeper', 30), $profile);
    $quitter = reuseFiber($transport, RuntimeProbe::tagged($server->baseUrl(), 'quitter', 30), $profile, stopAfter: 3);
    $keeper->start();
    $quitter->start();
    while (! $keeper->isTerminated() || ! $quitter->isTerminated()) {
        foreach ([$keeper, $quitter] as $fiber) {
            if (! $fiber->isTerminated()) {
                $fiber->resume();
            }
        }
    }
    $stats = RuntimeProbe::settle($server, 'openConnections', 0);

    expect(RuntimeProbe::problems(reusePairs($keeper->getReturn()), 'keeper', 30))->toBe([])
        ->and(array_column($quitter->getReturn(), 0))->toEqualCanonicalizing(['quitter-0', 'quitter-1', 'quitter-2'])
        ->and($stats['connections'])->toBe(2)
        ->and($stats['openConnections'])->toBe(0)
        ->and($transport->privatePoolsOpen())->toBe(0);
});

it('reuse off: a send suspended forever holds only its own connection, released when its fiber is destroyed', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Off);
    $profile = Batch::profile($server, 'off-stuck', ['window' => 5]);
    $server->rules(...RuntimeProbe::tagRules(['stuck', 'other'], 10, delayMs: 10));

    $stuck = reuseFiber($transport, RuntimeProbe::tagged($server->baseUrl(), 'stuck', 10), $profile);
    $stuck->start();
    $stuck->resume(); // two answers taken, three still in flight; never resumed again
    $other = iterator_to_array($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'other', 10), $profile));
    $whileStuck = RuntimeProbe::settle($server, 'openConnections', 1);
    $poolsWhileStuck = $transport->privatePoolsOpen();
    unset($stuck);  // destroying the suspended fiber runs the generator's finally block
    gc_collect_cycles();
    $after = RuntimeProbe::settle($server, 'openConnections', 0);

    expect(RuntimeProbe::problems($other, 'other', 10))->toBe([])
        ->and($whileStuck['connections'])->toBe(2)
        ->and($whileStuck['openConnections'])->toBe(1)   // the other send already closed its own
        ->and($poolsWhileStuck)->toBe(1)
        ->and($after['openConnections'])->toBe(0)
        ->and($transport->privatePoolsOpen())->toBe(0);
});

it('reuse off: close() leaves a running send alone; it still finishes on its own connection', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Off);
    $profile = Batch::profile($server, 'off-close', ['window' => 4]);
    $server->rules(...RuntimeProbe::tagRules(['run'], 8, delayMs: 5));

    $answers = [];
    foreach ($transport->send(RuntimeProbe::tagged($server->baseUrl(), 'run', 8), $profile) as $id => $exchange) {
        $answers[$id] = $exchange;
        if (count($answers) === 1) {
            $transport->close();
        }
    }

    expect(RuntimeProbe::problems($answers, 'run', 8))->toBe([])
        ->and($server->stats()['connections'])->toBe(1)
        ->and($transport->privatePoolsOpen())->toBe(0);
});

it('reuse off with the default request path: the generator type and request ids are unchanged', function () use (&$server): void {
    $transport = new CurlTransport(new CurlPoolRegistry, reuse: ConnectionReuse::Off);
    $requests = [
        new Request('first', 'POST', $server->baseUrl().'/a', ['content-type' => 'application/json'], '{}'),
        new Request('second', 'POST', $server->baseUrl().'/b', ["bad\nname" => 'x'], '{}'), // refused locally
    ];

    $byId = Batch::collect($transport->send($requests, Batch::profile($server, 'off-ids')));

    expect(array_keys($byId))->toEqualCanonicalizing(['first', 'second'])
        ->and($byId['first'])->toHaveCount(1)
        ->and($byId['second'])->toHaveCount(1)
        ->and($byId['second'][0]->response)->toBeNull()
        ->and($transport->privatePoolsOpen())->toBe(0);
});
