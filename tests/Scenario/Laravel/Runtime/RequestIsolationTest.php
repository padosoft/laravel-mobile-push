<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Logging\LogRecord;
use Padosoft\MobilePush\Laravel\Logging\LogSink;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * Story (Octane-style, worst case): the application is booted ONCE and serves many requests in the same process with
 * the SAME container, so every package singleton (manager, senders, guards, token store, log buffer) is shared by all
 * of them, as in a queue worker or an Octane worker where the manager was resolved during boot. Each request belongs
 * to a different tenant, set through scopeUsing(), with its own credential (from the tenant resolver) and its own
 * device. Nothing of one request may be observable in the next: scope, credential, bearer, guard state, buffered
 * log records. What does leak when the application uses the API per request is pinned at the end, with the safe way.
 */

final class ProbeSink implements LogSink
{
    /** @var list<LogRecord> */
    public array $written = [];

    public function write(LogRecord ...$records): void
    {
        foreach ($records as $record) {
            $this->written[] = $record;
        }
    }

    public function flush(): void {}
}

function tenantNumber(string $scope): ?int
{
    return preg_match('/^tenant-(\d+)$/D', $scope, $m) === 1 ? (int) $m[1] : null;
}

/** Boot once: the tenant resolver and the log sink are configured the way a service provider would do it. */
function bootOnce(): array
{
    $sink = new ProbeSink;
    SinkManager::extend('probe', static fn (): LogSink => $sink);
    $world = W::install(['log.sinks' => ['probe'], 'log.buffer.size' => 100, 'guards.auth.threshold' => 2]);
    MobilePush::resolveConnectionUsing(static function (string $name, string $scope): ?array {
        $n = tenantNumber($scope);

        return $name === 'ios' && $n !== null ? W::apns(sprintf('K%09d', $n), team: sprintf('T%09d', $n)) : null;
    });

    return [$world, $sink];
}

/** The team (JWT iss) of the bearer on an APNs request. */
function teamOf(Request $request): string
{
    $bearer = substr($request->headers['authorization'] ?? '', strlen('bearer '));
    $payload = explode('.', $bearer)[1] ?? '';

    return (string) (json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true)['iss'] ?? '');
}

/**
 * One request of tenant $n: the middleware sets the scope, the controller sends to the tenant's devices, the
 * framework terminates the request (flush hooks).
 *
 * @return list<SendResult>
 */
function tenantRequest(int $n, int $devices = 1): array
{
    MobilePush::scopeUsing(static fn (): string => 'tenant-'.$n);
    $tokens = [];
    for ($d = 0; $d < $devices; $d++) {
        $tokens[] = W::apnsToken("tenant-{$n}-device-{$d}");
    }
    $results = W::all(MobilePush::connection('ios')->toTokens(Platform::Ios, $tokens)->send(PushMessage::make('Hello', 'Tenant '.$n)));
    app()->terminate();

    return $results;
}

afterEach(function (): void {
    SinkManager::forgetExtensions();
});

it('forty tenants in a row on one booted app: each request uses only its own scope, credential and bearer, and leaves no buffered log', function (): void {
    [$world, $sink] = bootOnce();

    for ($n = 0; $n < 40; $n++) {
        $wireBefore = count($world->pushRequests());
        $logsBefore = count($sink->written);
        $eventsBefore = count($world->eventsOf(PushBatchCompleted::class));

        $results = tenantRequest($n);

        $wire = array_slice($world->pushRequests(), $wireBefore);
        $logs = array_slice($sink->written, $logsBefore);
        $events = array_slice($world->eventsOf(PushBatchCompleted::class), $eventsBefore);
        expect(array_map(fn (SendResult $r): SendStatus => $r->status, $results))->toBe([SendStatus::Accepted])
            ->and($wire)->toHaveCount(1)
            ->and(teamOf($wire[0]))->toBe(sprintf('T%09d', $n))
            ->and(W::jwtHeader($wire[0])['kid'])->toBe(sprintf('K%09d', $n))
            ->and($wire[0]->url)->toEndWith('/3/device/'.W::apnsToken("tenant-{$n}-device-0"))
            ->and(array_map(fn (object $e): string => $e->binding->scope, $events))->toBe(['tenant-'.$n])
            ->and(array_map(fn (LogRecord $r): string => $r->scope, $logs))->toBe(['tenant-'.$n])
            ->and(app(SinkManager::class)->buffered())->toBe(0);
    }

    // Forty different credentials, forty bearers, no bearer ever sent for another tenant's team.
    $bearers = [];
    foreach ($world->pushRequests() as $request) {
        $bearers[teamOf($request)][] = $request->headers['authorization'];
    }
    expect($bearers)->toHaveCount(40)
        ->and(array_map(fn (array $b): int => count(array_unique($b)), array_values($bearers)))->toBe(array_fill(0, 40, 1));
});

it('a tenant whose credential is refused trips ITS breaker only: the next tenant on the same worker is unaffected', function (): void {
    [$world] = bootOnce();
    $world->answer = static fn (Request $r): ?Response => teamOf($r) === sprintf('T%09d', 7)
        ? new Response(403, ['content-type' => 'application/json'], '{"reason":"InvalidProviderToken"}')
        : null;

    tenantRequest(6);
    $refused = tenantRequest(7, devices: 4);
    $wireAfterRefusal = count($world->pushRequests());
    $next = tenantRequest(8);
    $again = tenantRequest(7);

    $wireFor = fn (int $n): int => count(array_filter($world->pushRequests(), fn (Request $r): bool => teamOf($r) === sprintf('T%09d', $n)));
    expect(array_map(fn (SendResult $r): SendStatus => $r->status, $next))->toBe([SendStatus::Accepted])
        ->and(count($world->pushRequests()))->toBe($wireAfterRefusal + 1)          // only tenant 8's push went out
        ->and(array_map(fn (SendResult $r): SendStatus => $r->status, $refused))->toBe(array_fill(0, 4, SendStatus::Failed))
        ->and($wireFor(7))->toBe(4)                                                  // one window, sent before the breaker opened
        ->and([$again[0]->status, $again[0]->reason?->code, $again[0]->attempts])   // the breaker keeps tenant 7 off the wire
        ->toBe([SendStatus::Skipped, ReasonCode::CircuitOpen, 0])
        ->and($wireFor(6))->toBe(1)
        ->and($wireFor(8))->toBe(1);
});

it('the safe way to scope a long-lived app: one scopeUsing() at boot that reads the current request', function (): void {
    [$world] = bootOnce();
    $current = null; // what the tenancy middleware sets for each request
    MobilePush::scopeUsing(static function () use (&$current): string {
        return $current ?? 'default';
    });

    $scopes = [];
    foreach ([3, 9, null, 4] as $tenant) {
        $current = $tenant === null ? null : 'tenant-'.$tenant;
        $scopes[] = MobilePush::currentScope();
        $current = null; // the request ends
    }

    expect($scopes)->toBe(['tenant-3', 'tenant-9', 'default', 'tenant-4']);
});

it('KNOWN LIMITATION: with a shared manager, a request that does not set scopeUsing() inherits the previous request\'s scope', function (): void {
    bootOnce();

    tenantRequest(5);                       // sets scopeUsing(fn () => 'tenant-5')
    $scopeOfNextRequest = MobilePush::currentScope(); // a later request (e.g. a public page) that sets nothing

    expect($scopeOfNextRequest)->toBe('tenant-5');
});

it('KNOWN LIMITATION: eligibleUsing() called per request accumulates: the checks of earlier requests keep running', function (): void {
    [$world] = bootOnce();
    $calls = [];
    for ($n = 0; $n < 3; $n++) {
        MobilePush::eligibleUsing(static function (Destination $d) use (&$calls, $n): bool {
            $calls[] = $n;

            return true;
        });
        tenantRequest($n);
    }

    // The third request ran the checks registered by the first and the second request too.
    expect($calls)->toBe([0, 0, 1, 0, 1, 2]);
});

it('KNOWN LIMITATION: runtime registrations are kept for the life of the process (one per scope and name, no unregister)', function (): void {
    bootOnce();
    W::apns('R000000000'); // warm the helpers
    gc_collect_cycles();
    $before = memory_get_usage();
    for ($n = 0; $n < 200; $n++) {
        MobilePush::register('extra', W::apns(sprintf('R%09d', $n)), 'registered-'.$n); // each tenant its own key
    }
    gc_collect_cycles();

    expect(MobilePush::connectionNames('registered-0'))->toContain('extra')
        ->and(MobilePush::connectionNames('registered-199'))->toContain('extra')
        ->and(memory_get_usage() - $before)->toBeGreaterThan(200 * 200); // at least the key material of every tenant ever registered
});
