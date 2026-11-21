<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Logging\LogRecord;
use Padosoft\MobilePush\Laravel\Logging\LogSink;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime\RedirectingTransport;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime\RuntimeWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story (Octane's request sandbox, simulated without Octane): the application is booted once; every request runs on a
 * CLONE of it (`clone $app`, the clone bound as the container and facade root, a cloned config repository) and is
 * terminated at the end. Singletons resolved during boot are shared by all requests; singletons first resolved during
 * a request live only in that request's clone. This is the mechanism of Laravel Octane's worker as we know it from
 * its source; Octane itself is not installed here, so its exact listeners are NOT exercised.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['recordLimit' => 100]);
    }
});

afterAll(function () use (&$server): void {
    $server?->stop();
});

afterEach(function (): void {
    SinkManager::forgetExtensions();
});

/** Run $handle as one request in a sandbox cloned from the booted application, Octane-style, then terminate it. */
function inSandbox(Closure $handle): mixed
{
    $base = Container::getInstance();
    assert($base instanceof Application);
    $sandbox = clone $base;
    $sandbox->instance('app', $sandbox);
    $sandbox->instance(Container::class, $sandbox);
    $sandbox->instance('config', clone $base->make('config'));
    Container::setInstance($sandbox);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($sandbox);

    try {
        return $handle($sandbox);
    } finally {
        $sandbox->terminate();
        Container::setInstance($base);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($base);
    }
}

final class SandboxProbeSink implements LogSink
{
    /** @var list<LogRecord> */
    public array $written = [];

    public function write(LogRecord ...$records): void
    {
        array_push($this->written, ...$records);
    }

    public function flush(): void {}
}

it('requests in sandboxes get a fresh manager each (nothing set in one is seen by the next) and still reuse ONE connection', function () use (&$server): void {
    if ($server === null) {
        $this->markTestSkipped(MockServer::unavailableReason());
    }
    $server->reset();
    $keyId = sprintf('S%09d', random_int(0, 999_999_999)); // the process registry is shared: a pool key of our own
    RuntimeWorld::install($server, ['connections' => ['ios' => RuntimeWorld::apns($server, $keyId)]]);
    // As SendingModule binds it: a singleton closure, so each sandbox that resolves it builds its own CurlTransport,
    // all of them on the per-process pool registry.
    app()->forgetInstance(Transport::class);
    app()->singleton(Transport::class, static fn (): Transport => new RedirectingTransport(new CurlTransport, $server->baseUrl()));
    W::forgetSingletons();
    // Octane warms the cache manager by default (config octane.warm): every sandbox then shares its stores.
    app('cache')->store();

    $seen = [];
    for ($r = 0; $r < 20; $r++) {
        $seen[] = inSandbox(static function (Application $app) use ($r): array {
            $inherited = MobilePush::currentScope();
            $runtime = MobilePush::connectionNames('tenant-x');
            MobilePush::scopeUsing(static fn (): string => 'request-'.$r);
            MobilePush::register('only-in-request-'.$r, W::apns(), 'tenant-x');
            $results = W::all(MobilePush::scope('default')->connection('ios')->toToken(Platform::Ios, RuntimeWorld::token($r))->send(PushMessage::make('Hi')));

            return [
                'scope' => $inherited,
                'runtime' => $runtime,
                'status' => $results[0]->status,
                'manager' => spl_object_id($app->make(PushManager::class)),
            ];
        });
    }
    inSandbox(static fn () => MobilePush::flush()); // close our pool

    $bearers = array_unique(array_map(fn (array $r): string => $r['headers']['authorization'] ?? '', $server->requests()));
    expect(array_unique(array_column($seen, 'scope')))->toBe(['default'])          // no request saw the previous one's scope
        ->and(array_map(fn (array $s): array => $s['runtime'], $seen))->toBe(array_fill(0, 20, ['ios']))
        ->and(array_map(fn (array $s): SendStatus => $s['status'], $seen))->toBe(array_fill(0, 20, SendStatus::Accepted))
        ->and($server->stats()['received'])->toBe(20)
        ->and($server->stats()['connections'])->toBe(1)                            // pools outlive the sandbox (per-process registry)
        ->and($bearers)->toHaveCount(1);                                           // one provider token, shared through the cache store
});

it('a manager resolved during boot (warm) is shared by every sandbox: per-request state leaks, as with a plain worker', function (): void {
    W::install(['connections' => ['ios' => W::apns()]]);
    MobilePush::scopeUsing(static fn (): string => 'boot'); // e.g. called in a service provider: resolves the manager now

    $first = inSandbox(static function (Application $app): array {
        MobilePush::scopeUsing(static fn (): string => 'tenant-a');

        return [spl_object_id($app->make(PushManager::class)), MobilePush::currentScope()];
    });
    $second = inSandbox(static fn (Application $app): array => [spl_object_id($app->make(PushManager::class)), MobilePush::currentScope()]);

    expect($second[0])->toBe($first[0])
        ->and($second[1])->toBe('tenant-a'); // the scope of the previous request
});

it('DEFECT (Octane only): log records buffered by a sandbox-resolved SinkManager are never flushed at terminate', function (): void {
    $sink = new SandboxProbeSink;
    SinkManager::extend('probe', static fn (): LogSink => $sink);
    $world = W::install(['connections' => ['ios' => W::apns()], 'log.sinks' => ['probe'], 'log.buffer.size' => 100]);

    // The same request on the booted app (queue worker, php-fpm): flushed by the terminating hook.
    W::all(MobilePush::connection('ios')->toToken(Platform::Ios, W::apnsToken('plain'))->send(PushMessage::make('Hi')));
    app()->terminate();
    $flushedOnPlainApp = count($sink->written);

    // In a sandbox: the manager and the SinkManager are resolved in the clone; LoggingModule's terminating hook
    // closes over the BOOTED application, sees the SinkManager as unresolved there, and does not flush.
    W::forgetSingletons();
    $buffered = inSandbox(static function (Application $app): int {
        W::all(MobilePush::connection('ios')->toToken(Platform::Ios, W::apnsToken('sandboxed'))->send(PushMessage::make('Hi')));

        return $app->make(SinkManager::class)->buffered();
    });

    expect($flushedOnPlainApp)->toBe(1)
        ->and(count($world->pushRequests()))->toBe(2)
        ->and($buffered)->toBe(1)
        ->and(count($sink->written))->toBe(1); // the sandboxed request's record was lost with its clone
});

it('KNOWN LIMITATION: with the "array" cache store resolved per request, every sandboxed request mints a new APNs token', function (): void {
    $world = W::install(['connections' => ['ios' => W::apns()]]);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');

    for ($r = 0; $r < 3; $r++) {
        inSandbox(static fn () => W::all(MobilePush::connection('ios')->toToken(Platform::Ios, W::apnsToken('r'.$r))->send(PushMessage::make('Hi'))));
    }

    // Three requests, three provider tokens: Apple answers 429 TooManyProviderTokenUpdates when this goes on.
    // Use a shared store (redis, database, memcached) for mobile-push.cache.store under Octane.
    expect(array_unique(array_map(fn ($r): string => $r->headers['authorization'], $world->pushRequests())))->toHaveCount(3);
});
