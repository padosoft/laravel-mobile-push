<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\ReportsAvailability;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Cache\CacheHealth;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Queue\DeliveryRelay;
use Padosoft\MobilePush\Laravel\Queue\LedgerSweeper;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\DownStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime\RuntimeWorld;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;
use Psr\Clock\ClockInterface;

require_once dirname(__DIR__).'/Cache/CacheKit.php';

/*
 * Release test table, row "concurrent retries and cache unavailable": requests counted by the mock; one definitive
 * transition per effect.
 *
 * Story: a shop queues one order notification to six iPhones on the durable profile. The package's cache store
 * (`mobile-push.cache.store`: the guards, the provider-token store, the follow-up markers, the settlement marker) is
 * DOWN for the whole story: every call to it throws, locks included. Two relay workers work the same effect at the
 * same moment: the second one starts while the first one is handing its first requests to the transport. APNs,
 * played by the local HTTP/2 mock server over the package's real CurlTransport, answers 503 ServiceUnavailable to the
 * first request for three of the phones and 200 to everything else. Apple's minimum delay after a 5xx is 15 minutes
 * (docs/retries-and-limits.md); then the clock jumps from one due attempt to the next, the scheduler runs the sweeper
 * and the worker runs every queued follow-up job whose delay is over. The retry plan allows exactly one more request
 * for each of the three phones, and that request is accepted.
 *
 * Documented behaviour of an unreachable store (docs/retries-and-limits.md "When the cache behind the guards is down",
 * docs/runtimes.md section 6): the guards fail open, each process signs its own provider token, nothing throws.
 *
 * Every story runs twice: with the store down, and with a working `array` store as the control (same code, same
 * mock, same interleaving), so that a difference between the two is the cache outage and nothing else.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['recordLimit' => 1000]);
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
    $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
});

/** Starts a second relay worker on the same effect the first time anything is handed to the transport. */
final class InterleavingTransport implements Transport
{
    /** @var (Closure(): void)|null */
    public ?Closure $onFirstSend = null;

    public function __construct(private readonly Transport $inner) {}

    public function send(iterable $requests, ConnectionProfile $profile): Generator
    {
        $hook = $this->onFirstSend;
        $this->onFirstSend = null;
        if ($hook !== null) {
            $hook();   // runs before worker A's requests reach curl: worker B claims and sends the rest meanwhile
        }

        yield from $this->inner->send($requests, $profile);
    }

    public function close(?string $poolKey = null): void
    {
        $this->inner->close($poolKey);
    }
}

/** Requests per device token, counted by the mock server. @param list<string> $tokens @return array<string, int> */
function mockRequestsPerToken(MockServer $server, array $tokens): array
{
    $perToken = array_fill_keys($tokens, 0);
    foreach ($server->requests() as $request) {
        $token = substr((string) $request['path'], strlen('/3/device/'));
        $perToken[$token] = ($perToken[$token] ?? 0) + 1;
    }

    return $perToken;
}

/**
 * @return array{
 *     tokens: list<string>, retried: list<string>, firstRound: array<string, int>, perToken: array<string, int>,
 *     bearers: list<string>, thrown: list<string>, states: array<string, string>, attempts: list<int>,
 *     rewrites: list<string>, terminalSeen: array<string, true>, secondRan: bool, storeCalls: int,
 *     guardsAvailable: ?bool
 * }
 */
function concurrentRetryStory(MockServer $server, bool $cacheDown): array
{
    Queue::fake();
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    app()->instance(ClockInterface::class, $clock);
    Carbon::setTestNow(Carbon::instance($clock->now()));

    $down = new DownStore;
    config()->set('cache.stores.mp-down', ['driver' => 'mp-down']);
    Cache::extend('mp-down', function () use ($down) {   // not static: Laravel 12 binds the closure
        return new Repository($down);
    });

    $world = RuntimeWorld::install($server, [
        'connections' => ['ios' => RuntimeWorld::apns($server)],
        'delivery.profile' => 'durable',
        'ledger.lease_seconds' => 60,
        'queue.chunk' => 2,
        'cache.store' => $cacheDown ? 'mp-down' : 'array',
    ]);
    $interleaving = new InterleavingTransport($world->transport);
    app()->instance(Transport::class, $interleaving);
    foreach ([DatabaseLedger::class, DeliveryRelay::class, LedgerSweeper::class, GuardStore::class, TokenStore::class, CacheHealth::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
    foreach (LedgerModule::migrationPaths() as $path) {
        (require $path)->up();
    }

    $tokens = array_map(static fn (int $i): string => RuntimeWorld::token(700 + $i), range(0, 5));
    $retried = [$tokens[0], $tokens[3], $tokens[5]];
    $rules = [];
    foreach ($retried as $token) {
        $rules[] = ['path' => '^/3/device/'.$token.'$', 'times' => 1, 'action' => 'respond', 'status' => 503,
            'headers' => ['content-type' => 'application/json', 'apns-id' => '22222222-3333-4444-8555-666666666666'], 'body' => '{"reason":"ServiceUnavailable"}'];
    }
    $rules[] = ['path' => '^/3/device/', 'action' => 'respond', 'status' => 200, 'headers' => ['apns-id' => '11111111-2222-4333-8444-555555555555'], 'body' => ''];
    $server->rules(...$rules);

    $effectId = MobilePush::connection('ios')->toTokens(Platform::Ios, $tokens)->queue(PushMessage::make('Order 1042', 'Shipped')->ttl(7200));

    // A queue worker runs a job; whatever escapes the job is what the worker would see as a failed job.
    /** @var list<string> $thrown */
    $thrown = [];
    $ran = new SplObjectStorage;
    $run = static function (RelayDeliveriesJob $job) use (&$thrown, $ran): void {
        $ran->attach($job);
        try {
            app()->call([$job, 'handle']);
        } catch (Throwable $e) {
            $thrown[] = 'relay job: '.$e::class.': '.$e->getMessage();
        }
    };

    // The ledger rows after every step: once a row is terminal it must never change again (state, version, time).
    $terminal = ['accepted', 'rejected', 'failed', 'expired', 'cancelled', 'outcome_unknown', 'skipped'];
    /** @var array<string, string> $final */
    $final = [];
    /** @var list<string> $rewrites */
    $rewrites = [];
    $snapshot = static function () use (&$final, &$rewrites, $terminal): void {
        foreach (DB::table('mobile_push_deliveries')->get(['id', 'state', 'version', 'accepted_at']) as $row) {
            $now = $row->state.'|'.$row->version.'|'.($row->accepted_at ?? '-');
            if (isset($final[$row->id]) && $final[$row->id] !== $now) {
                $rewrites[] = $row->id.': '.$final[$row->id].' -> '.$now;
            }
            if (! isset($final[$row->id]) && in_array($row->state, $terminal, true)) {
                $final[$row->id] = $now;
            }
        }
    };

    $secondRan = false;
    $interleaving->onFirstSend = static function () use (&$secondRan, $run, $snapshot, $effectId): void {
        $secondRan = true;
        $snapshot();
        $run(new RelayDeliveriesJob('default', $effectId));
        $snapshot();
    };

    $first = Queue::pushed(RelayDeliveriesJob::class)->first();
    $run($first);
    $snapshot();
    $run($first);   // a worker retries a job that failed (or is redelivered): the rerun must be harmless
    $snapshot();
    $firstRound = mockRequestsPerToken($server, $tokens);

    // Then time passes the way a worker and the scheduler see it: the clock jumps to the next due attempt, the
    // sweeper runs (it queues a scope relay when work is due) and every queued job whose delay is over runs. A 503
    // pauses the whole Apple team for 15 minutes, so a phone whose first request only leaves later pauses it again.
    $open = static fn (): int => DB::table('mobile_push_deliveries')->whereNotIn('state', $terminal)->count();
    for ($round = 0; $round < 8 && $open() > 0; $round++) {
        $next = (int) DB::table('mobile_push_deliveries')->whereNotIn('state', $terminal)->min('next_attempt_at');
        $target = max($clock->now()->getTimestamp() + 1, intdiv($next, 1000) + 1);
        $clock->set((new DateTimeImmutable)->setTimestamp($target));
        Carbon::setTestNow(Carbon::instance($clock->now()));
        try {
            app(LedgerSweeper::class)->sweep();
        } catch (Throwable $e) {
            $thrown[] = 'sweeper: '.$e::class.': '.$e->getMessage();
        }
        for ($pass = 0; $pass < 5; $pass++) {
            $due = Queue::pushed(RelayDeliveriesJob::class)->reject(static fn (RelayDeliveriesJob $job): bool => $ran->contains($job)
                || ($job->followUpAt !== null && $job->followUpAt > $clock->now()->getTimestamp()));
            if ($due->isEmpty()) {
                break;
            }
            foreach ($due as $job) {
                $run($job);
                $snapshot();
            }
        }
    }

    $guards = app(GuardStore::class);
    $rows = DB::table('mobile_push_deliveries')->get(['id', 'state', 'attempts']);
    $attempts = $rows->pluck('attempts')->map(static fn ($n): int => (int) $n)->sort()->values()->all();

    return [
        'tokens' => $tokens,
        'retried' => $retried,
        'firstRound' => $firstRound,
        'perToken' => mockRequestsPerToken($server, $tokens),
        'bearers' => array_map(static fn (array $r): string => (string) ($r['headers']['authorization'] ?? ''), $server->requests()),
        'thrown' => $thrown,
        'states' => $rows->pluck('state', 'id')->all(),
        'attempts' => $attempts,
        'rewrites' => $rewrites,
        'terminalSeen' => array_fill_keys(array_keys($final), true),
        'secondRan' => $secondRan,
        'storeCalls' => $down->calls,
        'guardsAvailable' => $guards instanceof ReportsAvailability ? $guards->available() : null,
    ];
}

it('two relays at once with the cache down: every phone is requested once in the first round, never twice, counted by the mock', function (bool $cacheDown) use (&$server): void {
    $story = concurrentRetryStory($server, $cacheDown);

    expect($story['secondRan'])->toBeTrue()
        ->and(max($story['firstRound']))->toBe(1)   // two workers on one effect: no phone requested twice
        // the provider token store is down too: every request still carries a locally signed provider token
        ->and($story['bearers'])->each->toStartWith('bearer eyJ');
    if ($cacheDown) {
        // The guards fail open: the 503 pause of the Apple team (throttle gate) lives in the cache, so it is not
        // enforced and all six phones are requested in the first round. (In the control the pause is shared: the
        // phones claimed after the first 503 wait without a request, so the first round depends on the claim order.)
        expect($story['firstRound'])->toBe(array_fill_keys($story['tokens'], 1))
            ->and($story['storeCalls'])->toBeGreaterThan(0)       // the down store really was the package's store
            ->and($story['guardsAvailable'])->toBeFalse();
    }
    // a phone APNs accepted is never sent again, whatever ran afterwards
    foreach (array_diff($story['tokens'], $story['retried']) as $token) {
        expect($story['perToken'][$token])->toBe(1);
    }
})->with(['cache down' => true, 'cache up (control)' => false]);

it('nothing throws out of a relay run or the sweeper while the cache is down', function (bool $cacheDown) use (&$server): void {
    $story = concurrentRetryStory($server, $cacheDown);

    expect($story['thrown'])->toBe([]);
})->with(['cache down' => true, 'cache up (control)' => false]);

it('after the provider minimum delay each 503 phone gets exactly the one retry the plan allows, and every delivery ends in one terminal state, written once', function (bool $cacheDown) use (&$server): void {
    $story = concurrentRetryStory($server, $cacheDown);

    $expected = [];
    foreach ($story['tokens'] as $token) {
        $expected[$token] = in_array($token, $story['retried'], true) ? 2 : 1;   // 503, then 200
    }
    expect($story['perToken'])->toBe($expected)
        ->and(array_count_values($story['states']))->toBe(['accepted' => 6])
        // the ledger's attempt counters agree with the mock's request counts
        ->and($story['attempts'])->toBe([1, 1, 1, 2, 2, 2])
        ->and($story['terminalSeen'])->toHaveCount(6)
        ->and($story['rewrites'])->toBe([]);
})->with(['cache down' => true, 'cache up (control)' => false]);
