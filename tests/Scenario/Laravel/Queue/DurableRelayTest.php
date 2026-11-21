<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Sending\Events\BindingMismatchDetected;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../Devices/DeviceWorld.php';

/*
 * Durable profile, the relay: deferral without sleeping, the registry checked right before the network, forget,
 * a re-assigned connection, receipt markers.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->w = QueueWorld::boot('durable', devices: true);
    $this->w->dispatcher->add($this->w->kit->apnsDriver());
    $this->queue = static fn (iterable $destinations, string $key = 'order-42', ?BindingRef $binding = null, ?PushMessage $message = null): string => app(QueuedSender::class)
        ->queue($binding ?? SendKit::apnsBinding(), $message ?? PushMessage::make('Order', 'Shipped')->ttl(7200), $destinations, SendKit::live(effectKey: $key), new QueueOptions);
    $this->firstRelay = static fn (): RelayDeliveriesJob => Queue::pushed(RelayDeliveriesJob::class)->first();
});

it('deferred then delivered: a 503 defers for 15 minutes, nobody sleeps, one follow-up runs then and attempt 2 is accepted', function (): void {
    $effectId = ($this->queue)(QueueWorld::tokens(3));
    $this->w->transport->respondUsing(static fn () => SendKit::apnsReason(503, 'ServiceUnavailable'));

    $this->w->run(($this->firstRelay)());
    // A second relay of the same effect before the time (a duplicate job, the sweeper) adds no second follow-up.
    $this->w->run(new RelayDeliveriesJob('default', $effectId));

    $followUps = Queue::pushed(RelayDeliveriesJob::class)->filter(static fn (RelayDeliveriesJob $j): bool => $j->followUpAt !== null)->values();
    expect(QueueWorld::deliveryStates())->toBe(['deferred' => 3])
        ->and($this->w->kit->sleeps)->toBe([])
        ->and($this->w->transport->sentCount())->toBe(3)
        ->and($followUps)->toHaveCount(1)
        ->and($followUps[0]->effectId)->toBe($effectId)
        ->and($followUps[0]->delay)->toBeGreaterThanOrEqual(15 * 60)
        ->and($followUps[0]->delay)->toBeLessThan(15 * 60 + 5);

    $this->w->transport->respondUsing(static fn () => new Response(200));
    $this->w->advance($followUps[0]->delay);
    $this->w->run($followUps[0]);

    expect(QueueWorld::deliveryStates())->toBe(['accepted' => 3])
        ->and(DB::table('mobile_push_deliveries')->pluck('attempts')->unique()->values()->all())->toBe([2])
        ->and($this->w->transport->sentCount())->toBe(6)
        ->and($this->w->kit->sleeps)->toBe([]);
});

it('the phone changed hands while queued: the device now belongs to another owner, the delivery is skipped, zero requests', function (): void {
    $registrar = app(DeviceRegistrar::class);
    $token = QueueWorld::token(1);
    $device = $registrar->register(SendKit::apnsBinding(), $token, DeviceWorld::user(1));
    ($this->queue)([$device->destination()]);

    $registrar->register(SendKit::apnsBinding(), $token, DeviceWorld::user(2));   // logged in as someone else
    $this->w->run(($this->firstRelay)());

    $row = DB::table('mobile_push_deliveries')->first();
    expect($this->w->transport->sentCount())->toBe(0)
        ->and($row->state)->toBe('skipped')
        ->and($row->reason_code)->toBe('policy_ineligible')
        ->and($row->raw_reason)->toBe('owner_changed');
});

it('a registry device that is still the same phone and owner is resolved and sent', function (): void {
    $device = app(DeviceRegistrar::class)->register(SendKit::apnsBinding(), QueueWorld::token(5), DeviceWorld::user(1));
    ($this->queue)([$device->destination()]);

    $this->w->run(($this->firstRelay)());

    expect($this->w->apnsRequestsPerToken())->toBe([QueueWorld::token(5)->value() => 1])
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 1]);
});

it('forget me with jobs pending: after forgetOwner the relay sends nothing for that owner', function (): void {
    $registrar = app(DeviceRegistrar::class);
    $alice = DeviceWorld::user(7);
    $phone = $registrar->register(SendKit::apnsBinding(), QueueWorld::token(1), $alice);
    $tablet = $registrar->register(SendKit::apnsBinding(), QueueWorld::token(2), $alice);
    ($this->queue)([$phone->destination(), $tablet->destination()]);

    $this->w->ledger()->forgetOwner('default', $alice->getMorphClass(), '7');
    $registrar->detachOwner($alice);
    $this->w->run(($this->firstRelay)());

    expect($this->w->transport->sentCount())->toBe(0)
        ->and(QueueWorld::deliveryStates())->toBe(['cancelled' => 2]);
});

it('a relay for a connection that was re-assigned to another app records BindingMismatch and sends nothing', function (): void {
    $stale = new BindingRef('default', 'ios', Provider::Apns, 'com.previous.app', Environment::Production);
    ($this->queue)(QueueWorld::tokens(2), binding: $stale);

    Event::fake([BindingMismatchDetected::class]);
    $sink = QueueWorld::recordingSinks();
    $this->w->run(($this->firstRelay)());

    expect($this->w->transport->sentCount())->toBe(0)
        ->and(QueueWorld::deliveryStates())->toBe(['skipped' => 2])
        ->and(DB::table('mobile_push_deliveries')->pluck('reason_code')->unique()->values()->all())->toBe(['binding_mismatch'])
        ->and(DB::table('mobile_push_deliveries')->pluck('attempts')->unique()->values()->all())->toBe([0])
        // the same rule as a direct send: one event per run, one log record per destination
        ->and(array_map(static fn ($r): string => $r->status->value.'/'.$r->reasonCode->value.'/'.$r->attempts, $sink->records))
        ->toBe(['skipped/binding_mismatch/0', 'skipped/binding_mismatch/0']);
    Event::assertDispatchedTimes(BindingMismatchDetected::class, 1);
    Event::assertDispatched(BindingMismatchDetected::class, static fn (BindingMismatchDetected $e): bool => $e->connection === 'ios'
        && $e->expected?->appId === 'com.previous.app' && $e->current !== null && $e->current->appId !== 'com.previous.app');
});

it('with receipts on, the relay embeds the mp_r marker for an installation and never for a topic', function (): void {
    config()->set('mobile-push.receipts.enabled', true);
    $this->w->dispatcher->add($this->w->kit->fcmDriver());
    $this->w->transport->respondUsing(static fn () => SendKit::fcmOk());
    ($this->queue)([SendKit::fcmToken(1), FcmTopic::of('news')], binding: SendKit::fcmBinding());

    $this->w->run(($this->firstRelay)());

    $bodies = array_map(static fn (Request $r): array => json_decode($r->body, true)['message'], $this->w->transport->requests());
    $toToken = array_values(array_filter($bodies, static fn (array $m): bool => isset($m['token'])));
    $toTopic = array_values(array_filter($bodies, static fn (array $m): bool => isset($m['topic'])));
    expect($toToken)->toHaveCount(1)
        ->and($toTopic)->toHaveCount(1)
        ->and($toToken[0]['data']['mp_r'] ?? null)->toBeString()->not->toBeEmpty()
        ->and(array_key_exists('mp_r', $toTopic[0]['data'] ?? []))->toBeFalse()
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 2]);
});

it('a relay stops claiming at its chunk deadline and hands the rest over to a fresh run at once', function (): void {
    config()->set('mobile-push.queue.chunk', 2);
    config()->set('mobile-push.queue.chunk_deadline', 10);
    $effectId = ($this->queue)(QueueWorld::tokens(6));
    $this->w->transport->respondUsing(function () {
        $this->w->advance(4);   // a slow provider: 4 s per request

        return new Response(200);
    });

    $this->w->run(($this->firstRelay)());

    $handover = Queue::pushed(RelayDeliveriesJob::class)->last();
    expect($this->w->transport->sentCount())->toBe(2)
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 2, 'pending' => 4])
        ->and([$handover->effectId, $handover->delay, $handover->followUpAt !== null])->toBe([$effectId, null, true]);

    $this->w->transport->respondUsing(static fn () => new Response(200));
    $this->w->run($handover);

    expect(QueueWorld::deliveryStates())->toBe(['accepted' => 6])
        ->and(array_values(array_unique($this->w->apnsRequestsPerToken())))->toBe([1]);
});
