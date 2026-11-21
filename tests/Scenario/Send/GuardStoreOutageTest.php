<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\ReportsAvailability;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: the cache server behind the rate limiter and the circuit breakers goes away in the middle of the day.
 * Through GuardStore an unreachable backend and a contended one look the same (reads give null, compare-and-set
 * gives false); what must happen is opposite. Unreachable: the protection is gone but the pushes still go out.
 * Contended: nothing is granted, nothing is sent, and the destinations are deferred. A thrown send is never right.
 */
function guardsOn(SendKit $kit, GuardStore $store, int $ratePerMinute): Guards
{
    return new Guards(
        new RateLimiter($store, $kit->clock, $ratePerMinute, 0.0, null, []),
        new ThrottleGate($store, $kit->clock),
        new AuthBreaker($store, $kit->clock, 5, 60),
        new DestinationQualityBreaker($store, $kit->clock, 200),
        $kit->clock,
    );
}

/** What CacheGuardStore looks like from outside while its backend is unreachable. */
final class UnreachableGuardStore implements GuardStore, ReportsAvailability
{
    public int $calls = 0;

    public function get(string $key): ?string
    {
        $this->calls++;

        return null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->calls++;
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        $this->calls++;

        return false;
    }

    public function available(): bool
    {
        return false;
    }
}

/** A live store where another writer always wins the compare-and-set. */
final class AlwaysBeatenGuardStore implements GuardStore, ReportsAvailability
{
    public function get(string $key): ?string
    {
        return null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void {}

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        return false;
    }

    public function available(): bool
    {
        return true;
    }
}

it('keeps sending when the guard store is unreachable: every push goes out and nothing throws', function () {
    $kit = new SendKit;
    $store = new UnreachableGuardStore;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $destinations = (function () {
        for ($i = 0; $i < 12; $i++) {
            yield SendKit::fcmToken($i);
        }
    })();

    $results = SendKit::all($kit->sender($transport, guardsOn($kit, $store, 500_000))
        ->send($kit->fcmDriver(window: 5), PushMessage::make('Order shipped', 'Arrives Friday'), $destinations, SendKit::live()));

    expect($transport->requests())->toHaveCount(12)
        ->and($results)->toHaveCount(12)
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Accepted)
        ->and($store->calls)->toBeGreaterThan(0)       // the guards did consult the store
        ->and($store->calls)->toBeLessThan(200);       // and did not spin through their retry loop on a dead backend
});

it('defers every destination, with zero requests, when the guard state is alive but too contended to update', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $start = $kit->clock->now();
    $destinations = (function () {
        for ($i = 0; $i < 4; $i++) {
            yield SendKit::fcmToken($i);
        }
    })();

    $results = SendKit::all($kit->sender($transport, guardsOn($kit, new AlwaysBeatenGuardStore, 500_000))
        ->send($kit->fcmDriver(window: 5), PushMessage::make('Order shipped', 'Arrives Friday'), $destinations, SendKit::live()));

    expect($transport->requests())->toHaveCount(0)
        ->and($results)->toHaveCount(4)
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Deferred)
        ->and(array_map(fn ($r) => $r->reason->code, $results))->each->toBe(ReasonCode::QuotaExceeded)
        ->and(array_map(fn ($r) => $r->attempts, $results))->each->toBe(0)
        ->and(array_map(fn ($r) => $r->nextAttemptAt > $start, $results))->each->toBeTrue()
        ->and($results[0]->reason->hint)->toContain('contended');
});
