<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Exceptions\TransportConfigurationException;
use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Stories about the collaborators of one send failing: a consent registry, the guard cache, the transport. The
 * rule is the same everywhere: before anything happened a failure is a configuration problem and throws from
 * send(); once something was sent or reported, every destination still gets exactly one result and nothing throws.
 */

/** A consent registry that answers the first windows and then goes away. */
final class FlakyConsentGate implements DestinationGate
{
    /** @var list<int> */
    public array $calls = [];

    /** @param  int  $failOnCall  1-based call that throws */
    public function __construct(private readonly int $failOnCall, private readonly bool $denyAll = false) {}

    public function check(GateContext $context, array $items): array
    {
        $this->calls[] = count($items);
        if (count($this->calls) === $this->failOnCall) {
            throw new RuntimeException('consent registry timed out');
        }

        return $this->denyAll ? array_map(static fn () => GateDenial::skip(ReasonCode::PolicyIneligible, 'No consent.'), $items) : [];
    }
}

/** The shared cache behind the guards, which can be switched off mid-send. Not a contention: a hard error. */
final class SwitchableGuardStore implements GuardStore
{
    public bool $down = false;

    public function __construct(private readonly InMemoryGuardStore $inner) {}

    public function get(string $key): ?string
    {
        $this->fail();

        return $this->inner->get($key);
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->fail();
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        $this->fail();

        return $this->inner->compareAndSet($key, $expected, $value, $ttlSeconds);
    }

    private function fail(): void
    {
        if ($this->down) {
            throw new RuntimeException('cache connection refused');
        }
    }
}

/** A transport whose n-th send() call throws, after yielding the first $yieldFirst exchanges of that call. */
final class BreakingTransport implements Transport
{
    private int $calls = 0;

    public function __construct(
        public readonly FakeTransport $inner,
        private readonly int $breakOnCall,
        private readonly Throwable $error,
        private readonly int $yieldFirst = 0,
    ) {}

    public function send(iterable $requests, ConnectionProfile $profile): Generator
    {
        if (++$this->calls !== $this->breakOnCall) {
            yield from $this->inner->send($requests, $profile);

            return;
        }
        if ($this->yieldFirst === 0) {
            throw $this->error;   // before pulling any request from the input
        }
        $given = 0;
        foreach ($this->inner->send($requests, $profile) as $id => $exchange) {
            yield $id => $exchange;
            if (++$given >= $this->yieldFirst) {
                throw $this->error;
            }
        }
    }

    public function close(?string $poolKey = null): void {}
}

function guardsOver(SendKit $kit, GuardStore $store): Guards
{
    return new Guards(
        new RateLimiter($store, $kit->clock, null, 0.0, null, []),
        new ThrottleGate($store, $kit->clock),
        new AuthBreaker($store, $kit->clock, 5, 60),
        new DestinationQualityBreaker($store, $kit->clock, 200),
        $kit->clock,
    );
}

it('throws from send() when the only gate fails before anything was sent, and sends nothing', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $gate = new FlakyConsentGate(failOnCall: 1);

    expect(fn () => $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live(gates: [$gate], window: 2)))
        ->toThrow(RuntimeException::class, 'consent registry timed out');
    expect($transport->requests())->toBe([]);
});

it('defers the window whose gate failed, 60 s ahead and unsent, once earlier windows were already reported', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $gate = new FlakyConsentGate(failOnCall: 2);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(5), SendKit::live(gates: [$gate], window: 2)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([
        SendStatus::Accepted, SendStatus::Accepted, SendStatus::Deferred, SendStatus::Deferred, SendStatus::Accepted,
    ])
        ->and(array_map(fn (Request $r) => SendKit::tokenOf($r), $transport->requests()))
        ->toBe([SendKit::apnsToken(0)->value(), SendKit::apnsToken(1)->value(), SendKit::apnsToken(4)->value()])
        ->and($results[2]->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($results[2]->reason->hint)->toContain(FlakyConsentGate::class)->toContain('RuntimeException')
        ->and($results[2]->nextAttemptAt)->toEqual($kit->clock->now()->modify('+60 seconds'))
        ->and($results[2]->attempts)->toBe(0)
        ->and($results[3]->nextAttemptAt)->toEqual($kit->clock->now()->modify('+60 seconds'));
});

it('defers with a gate failure even when the previous window was entirely denied: a report was already produced', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $gate = new FlakyConsentGate(failOnCall: 2, denyAll: true);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(4), SendKit::live(gates: [$gate], window: 2)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Skipped, SendStatus::Skipped, SendStatus::Deferred, SendStatus::Deferred])
        ->and($transport->requests())->toBe([]);
});

it('does not consult a later gate for a window an earlier gate denied entirely', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $consent = new FlakyConsentGate(failOnCall: 0, denyAll: true);
    $cap = new FlakyConsentGate(failOnCall: 0);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live(gates: [$consent, $cap], window: 2)));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Skipped)
        ->and($consent->calls)->toBe([2, 1])
        ->and($cap->calls)->toBe([]);
});

it('throws from send() when the guard cache fails with a hard error before anything was sent', function () {
    $kit = new SendKit;
    $store = new SwitchableGuardStore(new InMemoryGuardStore($kit->clock));
    $store->down = true;
    $transport = new FakeTransport;

    expect(fn () => $kit->sender($transport, guardsOver($kit, $store))->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()))
        ->toThrow(RuntimeException::class, 'cache connection refused');
    expect($transport->requests())->toBe([]);
});

/*
 * Story: the cache server dies between two windows of a campaign. The first window went out; the second cannot ask
 * the guards and must not be sent blind, nor thrown away: it is deferred.
 */
it('defers a later window as provider-unavailable, 60 s ahead, when the guard cache dies between windows', function () {
    $kit = new SendKit;
    $store = new SwitchableGuardStore(new InMemoryGuardStore($kit->clock));
    $transport = (new FakeTransport)->respondUsing(function () use ($store) {
        $store->down = true;   // the cache dies while the first window is on the wire

        return new Response(200);
    });

    $results = SendKit::all($kit->sender($transport, guardsOver($kit, $store))->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live(window: 1)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Deferred, SendStatus::Deferred])
        ->and($transport->requests())->toHaveCount(1)
        ->and($results[1]->reason->code)->toBe(ReasonCode::ProviderUnavailable)
        ->and($results[1]->reason->hint)->toContain('RuntimeException')
        ->and($results[1]->nextAttemptAt)->toEqual($kit->clock->now()->modify('+60 seconds'))
        ->and($results[1]->attempts)->toBe(0);
});

/*
 * Story: a Live Activity update was sent, its response was lost, and while waiting to send it again the cache died.
 * Something already left: no exception, the retry is deferred, and the unknown first attempt is not forgotten.
 */
it('defers an in-process retry when the guard cache dies after the first attempt of the first window', function () {
    $kit = new SendKit;
    $store = new SwitchableGuardStore(new InMemoryGuardStore($kit->clock));
    $transport = (new FakeTransport)->respondUsing(function () use ($store) {
        $store->down = true;

        return new TransportFailure(TransportEvidence::Unknown, 92, 'stream reset');
    });
    $sender = $kit->sender($transport, guardsOver($kit, $store), new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, inlineThresholdMs: 1000));

    [$result] = SendKit::all($sender->send($kit->apnsDriver(), LiveActivityMessage::update(['eta' => 5]), [ApnsLiveActivityToken::of(str_repeat('0f', 32))], SendKit::live()));

    expect($transport->requests())->toHaveCount(1)
        ->and($kit->sleeps)->toBe([1000])
        ->and($result->status)->toBe(SendStatus::Deferred)
        ->and($result->reason->code)->toBe(ReasonCode::ProviderUnavailable)
        ->and($result->attempts)->toBe(1)
        ->and($result->nextAttemptAt)->toEqual($kit->clock->now()->modify('+60 seconds'));
});

it('throws from send() when the transport refuses its configuration before anything was sent', function () {
    $kit = new SendKit;
    $transport = new BreakingTransport(new FakeTransport, 1, new TransportConfigurationException('HTTP/2 is not available.'));

    expect(fn () => $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()))
        ->toThrow(TransportConfigurationException::class);
    expect($transport->inner->requests())->toBe([]);
});

/*
 * Story: the first window went out, then the transport refuses the next batch with a configuration error (thrown,
 * by contract, before writing anything). Those destinations were provably not sent: Failed-retryable, deferred.
 */
it('reports a later window refused by the transport configuration as not sent, with that proof in the diagnostics', function () {
    $kit = new SendKit;
    $transport = new BreakingTransport(new FakeTransport, 2, new TransportConfigurationException('HTTP/2 is not available.'));
    $observer = new RecordingObserver;

    $results = SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live(window: 1)));

    expect($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($results[1]->status)->toBe(SendStatus::Deferred)
        ->and($results[1]->reason->code)->toBe(ReasonCode::TransportNotSent)
        ->and($results[1]->nextAttemptAt)->toEqual($kit->clock->now()->modify('+1000 milliseconds'))
        ->and($observer->attempts[1]->failure)->toBe([
            'evidence' => 'not_sent',
            'errno' => 0,
            'message' => 'The transport refused the batch before sending: '.TransportConfigurationException::class,
            'request_written' => false,
        ]);
});

/*
 * Story: the transport crashes with an ordinary bug before yielding anything. Bytes may have left: the outcome is
 * unknown, never "not sent", and nothing throws, not even in the very first window.
 */
it('reports OutcomeUnknown, without throwing, when the transport crashes before yielding any exchange', function () {
    $kit = new SendKit;
    $transport = new BreakingTransport(new FakeTransport, 1, new LogicException('bug in a transport'));
    $observer = new RecordingObserver;

    $results = SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::OutcomeUnknown)
        ->and($results[0]->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($observer->attempts[0]->failure)->toBe([
            'evidence' => 'unknown',
            'errno' => 0,
            'message' => 'The transport returned no result for this request (LogicException).',
            'request_written' => true,
        ]);
});

it('reports a request the transport silently dropped as OutcomeUnknown', function () {
    $kit = new SendKit;
    $dropping = new class implements Transport
    {
        public int $handed = 0;

        public function send(iterable $requests, ConnectionProfile $profile): Generator
        {
            foreach ($requests as $request) {
                $this->handed++;
                if ($this->handed === 1) {
                    yield $request->id => Exchange::responded($request, new Response(200));
                }
            }
        }

        public function close(?string $poolKey = null): void {}
    };
    $observer = new RecordingObserver;

    $results = SendKit::all($kit->sender($dropping, observers: [$observer])->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::OutcomeUnknown])
        ->and($observer->attempts[1]->failure['message'])->toBe('The transport returned no result for this request.')
        ->and($observer->attempts[1]->failure['evidence'])->toBe('unknown');
});

it('keeps a resumed item that arrives already expired from hiding the live items after it in the same window', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $policy = new RetryPolicy(jitter: 0.0);
    $stale = RetryBudget::start($policy, $kit->clock->now()->modify('-2 hours'), $kit->clock->now()->modify('-1 hour'));

    $results = SendKit::all($kit->sender($transport, policy: $policy)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [
        new SendItem(SendKit::apnsToken(1), $stale, 'stale'),
        SendKit::apnsToken(2),
        SendKit::apnsToken(3),
    ], SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Expired, SendStatus::Accepted, SendStatus::Accepted])
        ->and($transport->requests())->toHaveCount(2);
});

/*
 * Story: APNs refuses the key at the very moment the guard cache dies, so the refusal cannot be recorded. Guard
 * state is advisory: the lost sample must not be read as a failover. The destination fails with the refusal, once.
 */
it('does not invent a failover when the authentication failure cannot be recorded', function () {
    $kit = new SendKit;
    $store = new SwitchableGuardStore(new InMemoryGuardStore($kit->clock));
    $transport = (new FakeTransport)->respondUsing(function () use ($store) {
        $store->down = true;

        return SendKit::apnsReason(403, 'InvalidProviderToken');
    });
    $observer = new RecordingObserver;
    $driver = $kit->apnsDriver($kit->apnsCredential('OLDKEY1111'), $kit->apnsCredential('NEWKEY2222'));

    [$result] = SendKit::all($kit->sender($transport, guardsOver($kit, $store), observers: [$observer])->send($driver, PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live()));

    expect($transport->requests())->toHaveCount(1)
        ->and($result->status)->toBe(SendStatus::Failed)
        ->and($result->reason->code)->toBe(ReasonCode::AuthTokenInvalid)
        ->and($result->reason->rawReason)->toBe('InvalidProviderToken')
        ->and($result->attempts)->toBe(1)
        ->and($observer->transitions)->toBe([]);
});
