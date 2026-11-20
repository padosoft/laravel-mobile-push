<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: a "your table is ready" alert lives 30 seconds. FCM is briefly unavailable and asks for at least a minute.
 */
it('expires an alert whose next attempt would fall after its expiry, and never calls the provider after it', function () {
    $kit = new SendKit;
    $times = [];
    $transport = (new FakeTransport)->respondUsing(function () use ($kit, &$times) {
        $times[] = $kit->clock->now();

        return SendKit::fcmError(503, 'UNAVAILABLE', 'UNAVAILABLE');
    });
    $message = PushMessage::make('Table ready', 'Please come in')->ttl(30);
    $expiry = $kit->clock->now()->modify('+30 seconds');

    [$result] = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), $message, [SendKit::fcmToken(1)], SendKit::live()));

    expect($result->status)->toBe(SendStatus::Expired)
        ->and($result->reason->code)->toBe(ReasonCode::Expired)
        ->and($result->reason->rawReason)->toBe('UNAVAILABLE')
        ->and($result->attempts)->toBe(1)
        ->and($result->status->allowsFallback())->toBeTrue()
        ->and($transport->requests())->toHaveCount(1)
        ->and(array_map(fn ($t) => $t < $expiry, $times))->each->toBeTrue();

    // The remaining lifetime travelled with the request (ttl recomputed from now, never extended).
    expect(json_decode($transport->requests()[0]->body, true)['message']['android']['ttl'])->toBe('30s');
});

it('does not touch the network when a deferred destination is resumed after its expiry', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(SendKit::fcmError(503, 'UNAVAILABLE', 'UNAVAILABLE'));
    $observer = new RecordingObserver;
    $sender = $kit->sender($transport, observers: [$observer]);
    $driver = $kit->fcmDriver();
    $message = PushMessage::make('Flash sale', 'Ends in ten minutes')->ttl(600);

    [$deferred] = SendKit::all($sender->send($driver, $message, [SendKit::fcmToken(1)], SendKit::live()));
    $budget = $observer->results[0]['budget'];
    $kit->clock->advance(601);

    // The relay re-sends the same message; the relative ttl must not restart from the resume time.
    [$expired] = SendKit::all($sender->send($driver, $message, [new SendItem(SendKit::fcmToken(1), $budget, 'delivery-1')], SendKit::live()));

    expect($deferred->status)->toBe(SendStatus::Deferred)
        ->and($expired->status)->toBe(SendStatus::Expired)
        ->and($expired->deliveryId)->toBe('delivery-1')
        ->and($expired->attempts)->toBe(1)
        ->and($transport->requests())->toHaveCount(1);
});

/*
 * Story: the durable relay picks up a delivery that APNs answered with 503 fifteen minutes ago.
 */
it('continues the same retry budget when resuming from a ledger: attempts, deadline and apns-id are kept', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(SendKit::apnsReason(503, 'ServiceUnavailable'))->defaultTo(new Response(200));
    $observer = new RecordingObserver;
    $sender = $kit->sender($transport, observers: [$observer]);
    $driver = $kit->apnsDriver();
    $message = PushMessage::make('Receipt', 'Thanks for your order');
    $options = SendKit::live(effectKey: 'order-42-receipt');

    [$deferred] = SendKit::all($sender->send($driver, $message, [SendKit::apnsToken(1)], $options));
    ['budget' => $budget] = $observer->results[0];
    $stored = $budget->toArray();                                   // what a ledger row would hold
    $kit->clock->set($deferred->nextAttemptAt ?? throw new LogicException('no nextAttemptAt'));
    $kit->clock->advance(1);

    $resumed = new SendItem(SendKit::apnsToken(1), \Padosoft\MobilePush\Core\Retry\RetryBudget::fromArray($stored), 'delivery-7', $deferred->meta['message_id']);
    [$accepted] = SendKit::all($sender->send($driver, $message, [$resumed], $options));
    $final = $observer->results[1]['budget'];

    [$first, $second] = $transport->requests();
    expect($deferred->status)->toBe(SendStatus::Deferred)
        ->and($deferred->nextAttemptAt)->toEqual($kit->clock->now()->modify('-1 second'))
        ->and($accepted->status)->toBe(SendStatus::Accepted)
        ->and($accepted->attempts)->toBe(2)
        ->and($accepted->deliveryId)->toBe('delivery-7')
        ->and($final->startedAt)->toEqual($budget->startedAt)
        ->and($final->deadline())->toEqual($budget->deadline())
        ->and($second->headers['apns-id'])->toBe($first->headers['apns-id'])
        ->and($observer->results[1]['item'])->toBe($resumed);
});

it('derives the same apns-id for the same effect and destination, and different ones otherwise', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $sender = $kit->sender($transport);
    $driver = $kit->apnsDriver();
    $send = fn (string $key, int $token) => $sender->send($driver, PushMessage::make('A', 'B'), [SendKit::apnsToken($token)], SendKit::live(effectKey: $key))->counts();

    $send('effect-1', 1);
    $send('effect-1', 1);
    $send('effect-1', 2);
    $send('effect-2', 1);
    $sender->send($driver, PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live())->counts();

    $ids = array_map(fn (Request $r) => $r->headers['apns-id'], $transport->requests());
    expect($ids[0])->toBe($ids[1])
        ->and($ids[0])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(array_unique([$ids[0], $ids[2], $ids[3], $ids[4]]))->toHaveCount(4);
});
