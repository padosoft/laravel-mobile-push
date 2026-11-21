<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\SettlementProbe;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Queue\RelayFollowUps;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * The durable path outside campaigns: one effect with many destinations, many one-destination effects (the
 * transactional shape), the follow-ups of thousands of effects deferred at once, and the operator queries next to a
 * million final rows. Time per unit of work must not grow with the history in the tables.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->w = QueueWorld::boot('durable');
    $this->w->dispatcher->add($this->w->kit->apnsDriver());
    $this->queue = static fn (iterable $destinations, string $key): string => app(QueuedSender::class)
        ->queue(SendKit::apnsBinding(), PushMessage::make('Order', 'Shipped')->ttl(7200), $destinations, SendKit::live(effectKey: $key), new QueueOptions);
    $this->ran = new SplObjectStorage;
    $this->drain = function (): int {
        $n = 0;
        do {
            $progress = false;
            foreach (Queue::pushed(RelayDeliveriesJob::class) as $job) {
                if (! $this->ran->contains($job)) {
                    $this->ran[$job] = true;
                    $this->w->run($job);
                    $n++;
                    $progress = true;
                }
            }
        } while ($progress);

        return $n;
    };
});

/** Insert `$n` final (accepted) deliveries of one old effect straight into the table. */
function historyRows(int $n, string $scope = 'default'): string
{
    $effect = 'HISTORY'.str_pad((string) random_int(0, 99_999), 19, '0', STR_PAD_LEFT);
    DB::table('mobile_push_effects')->insert([
        'id' => $effect, 'scope' => $scope, 'binding' => '{}', 'binding_identity' => str_repeat('0', 64), 'effect_key' => $effect,
        'dedupe_key' => null, 'fingerprint' => 'v1:x', 'operation' => 'send', 'traffic_class' => 'transactional',
        'dedupe_until' => 0, 'created_at' => 0, 'updated_at' => 0,
    ]);
    $pdo = DB::connection()->getPdo();
    $pdo->beginTransaction();
    $insert = $pdo->prepare('insert into mobile_push_deliveries (id, effect_id, scope, kind, logical_key, state, attempts, next_attempt_at, version, accepted_at, created_at, updated_at, client_received_at) values (?, ?, ?, ?, ?, ?, 1, null, 2, 1, 1, 1, ?)');
    for ($i = 0; $i < $n; $i++) {
        $insert->execute([substr($scope, 0, 1).sprintf('%025d', $i), $effect, $scope, 'apns_device_token', 'h'.$i, 'accepted', $i % 3 === 0 ? 1 : null]);
    }
    $pdo->commit();

    return $effect;
}

/** @return float milliseconds of $work, best of 3 */
function bestMs(Closure $work): float
{
    $best = INF;
    for ($i = 0; $i < 3; $i++) {
        $t = hrtime(true);
        $work();
        $best = min($best, (hrtime(true) - $t) / 1e6);
    }

    return $best;
}

it('small effects deferred at the same instant (300 here, 10,000 in the slow group) share a bounded number of follow-up relays, and all are sent once due', function (int $effects): void {
    Event::fake([EffectSettled::class]);
    $this->w->transport->respondUsing(static fn (Request $r) => SendKit::apnsReason(429, 'TooManyRequests'));
    for ($i = 0; $i < $effects; $i++) {
        ($this->queue)([QueueWorld::token($i)], 'order-'.$i);
    }
    $first = ($this->drain)();
    $followUps = Queue::pushed(RelayDeliveriesJob::class)->count() - $effects;

    // One precise follow-up per effect up to the limit, then ONE scope-wide relay for the rest of the bucket.
    expect($first)->toBe($effects + $followUps)
        ->and($followUps)->toBeLessThanOrEqual(RelayFollowUps::MAX_EFFECT_FOLLOW_UPS + 2)
        ->and(QueueWorld::deliveryStates())->toBe(['deferred' => $effects]);

    $this->w->transport->respondUsing(static fn (Request $r) => null);   // the provider recovers
    $this->w->advance(120);
    foreach (Queue::pushed(RelayDeliveriesJob::class) as $job) {
        $this->ran->offsetUnset($job);   // the delayed follow-ups are now due: the queue hands them out
    }
    ($this->drain)();

    expect(QueueWorld::deliveryStates())->toBe(['accepted' => $effects])
        ->and(array_values(array_unique($this->w->apnsRequestsPerToken())))->toBe([2]);   // the 429, then the accept
    // Effects finished by the shared scope-wide relay are announced like the others: once each.
    Event::assertDispatchedTimes(EffectSettled::class, $effects);
})->with([300]);

it('10,000 small effects deferred at the same instant: measured', function (): void {
    $this->w->transport->respondUsing(static fn (Request $r) => SendKit::apnsReason(429, 'TooManyRequests'));
    for ($i = 0; $i < 10_000; $i++) {
        ($this->queue)([QueueWorld::token($i)], 'order-'.$i);
    }
    ($this->drain)();
    $followUps = Queue::pushed(RelayDeliveriesJob::class)->count() - 10_000;
    fwrite(STDERR, sprintf("\n   10,000 effects deferred at once: %d follow-up relay jobs (one per effect before W5-P: 10,000)\n", $followUps));

    expect($followUps)->toBeLessThanOrEqual(RelayFollowUps::MAX_EFFECT_FOLLOW_UPS + 2);
})->group('slow');

it('many one-destination effects: the time per effect does not grow with the number of effects already sent', function (): void {
    $batch = function (int $from, int $n): float {
        $t = hrtime(true);
        for ($i = $from; $i < $from + $n; $i++) {
            ($this->queue)([QueueWorld::token($i)], 'order-'.$i);
        }
        ($this->drain)();

        return (hrtime(true) - $t) / 1e6 / $n;
    };
    $early = $batch(0, 2_000);
    $batch(2_000, 46_000);                  // 48,000 effects sent in between
    $late = $batch(48_000, 2_000);
    fwrite(STDERR, sprintf("\n   one-destination effects: %.3f ms per effect for the first 2,000, %.3f ms for 2,000 after 48,000 (ratio %.2f)\n", $early, $late, $late / $early));

    expect(QueueWorld::deliveryStates())->toBe(['accepted' => 50_000])
        ->and($late / $early)->toBeLessThan(2.0);
})->group('slow');

it('one effect with 200,000 destinations is claimed, sent and recorded in constant time per destination', function (): void {
    $run = function (int $n, string $key): float {
        $t = hrtime(true);
        ($this->queue)(QueueWorld::tokens($n, 1_000_000 * (strlen($key))), $key);
        ($this->drain)();

        return (hrtime(true) - $t) / 1e6 / $n * 1000;
    };
    $small = $run(25_000, 'small');
    $large = $run(200_000, 'large-effect');
    fwrite(STDERR, sprintf("\n   one effect: %.1f ms per 1,000 destinations at 25,000, %.1f ms at 200,000 (ratio %.2f)\n", $small, $large, $large / $small));

    expect($large / $small)->toBeLessThan(2.0);
})->group('slow');

it('next to a million final rows, health, scopes with work, counts, the receipts funnel and the settlement probe stay fast', function (): void {
    $effectId = ($this->queue)(QueueWorld::tokens(50), 'live');
    $ledger = $this->w->ledger();
    assert($ledger instanceof ClientReceiptStore && $ledger instanceof SettlementProbe);
    $calls = [
        'health()' => static fn () => $ledger->health(),
        'health(scope)' => static fn () => $ledger->health('default'),
        'scopesWithWork()' => static fn () => $ledger->scopesWithWork(),
        'counts(effect)' => static fn () => $ledger->counts('default', $effectId),
        'effectFunnel()' => static fn () => $ledger->effectFunnel('default', $effectId),
        'hasOpenDeliveries()' => static fn () => $ledger->hasOpenDeliveries('default', $effectId),
        'claimDue()+reclaim+expire' => static fn () => $ledger->claimDue('default', 'probe', 100, 'NO-SUCH-EFFECT'),
    ];
    $before = array_map(bestMs(...), $calls);
    historyRows(1_000_000);
    historyRows(200_000, 'tenant-old');
    $after = array_map(bestMs(...), $calls);

    foreach ($calls as $name => $call) {
        fwrite(STDERR, sprintf("\n   %-32s %7.2f ms -> %7.2f ms with 1.2M final rows", $name, $before[$name], $after[$name]));
        expect($after[$name])->toBeLessThan(max(25.0, $before[$name] * 5), $name.' grew with the final rows');
    }
    expect($ledger->health()->due)->toBe(50)
        ->and($ledger->scopesWithWork())->toBe(['default']);
})->group('slow');
