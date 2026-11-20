<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: a flash sale alert that must not arrive late. ttl(0) means one delivery attempt, never stored by the
 * provider, never retried.
 */
function flashSale(): PushMessage
{
    return PushMessage::make('Flash sale', 'Ends in a minute')->ttl(0);
}

function lost(): TransportFailure
{
    return new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream 1 was not closed cleanly: INTERNAL_ERROR');
}

it('sends a now-or-never alert once, telling APNs not to store it', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(new Response(200));

    [$result] = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), flashSale(), [SendKit::apnsToken(1)], SendKit::live()));

    expect($transport->requests())->toHaveCount(1)
        ->and($transport->requests()[0]->headers['apns-expiration'])->toBe('0')
        ->and($result->status)->toBe(SendStatus::Accepted)
        ->and($result->attempts)->toBe(1);
});

it('sends a now-or-never alert once through FCM with a 0s ttl', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());

    [$result] = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), flashSale(), [SendKit::fcmToken(1)], SendKit::live()));

    expect($transport->requests())->toHaveCount(1)
        ->and(json_decode($transport->requests()[0]->body, true)['message']['android']['ttl'])->toBe('0s')
        ->and($result->status)->toBe(SendStatus::Accepted);
});

it('does not retry a now-or-never alert after a 503, not even when the budget is run again later', function (string $provider) {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $apns = $provider === 'apns';
    $transport = (new FakeTransport)->defaultTo($apns ? SendKit::apnsReason(503, 'ServiceUnavailable') : SendKit::fcmError(503, 'UNAVAILABLE', 'UNAVAILABLE'));
    $sender = $kit->sender($transport, observers: [$observer], policy: new RetryPolicy(jitter: 0.0, inlineThresholdMs: 5_000));
    $driver = $apns ? $kit->apnsDriver() : $kit->fcmDriver();
    $destination = $apns ? SendKit::apnsToken(1) : SendKit::fcmToken(1);

    [$result] = SendKit::all($sender->send($driver, flashSale(), [$destination], SendKit::live()));

    expect($result->status)->toBe(SendStatus::Expired)
        ->and($result->reason->code)->toBe(ReasonCode::Expired)
        ->and($result->attempts)->toBe(1)
        ->and($transport->requests())->toHaveCount(1)
        ->and($kit->sleeps)->toBe([]);

    $budget = $observer->results[0]['budget'];
    $kit->clock->advance(3600);
    [$again] = SendKit::all($sender->send($driver, flashSale(), [new SendItem($destination, RetryBudget::fromArray($budget->toArray()))], SendKit::live()));

    expect($again->status)->toBe(SendStatus::Expired)
        ->and($transport->requests())->toHaveCount(1);
})->with(['apns', 'fcm']);

it('reports OutcomeUnknown after one request when the response of a now-or-never alert is lost', function (string $provider) {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(lost());
    $apns = $provider === 'apns';
    // Even a policy that retries unknown outcomes of everything must not retry a now-or-never message.
    $sender = $kit->sender($transport, policy: new RetryPolicy(jitter: 0.0, inlineThresholdMs: 5_000));
    $message = flashSale()->replaceable();

    [$result] = SendKit::all($sender->send($apns ? $kit->apnsDriver() : $kit->fcmDriver(), $message, [$apns ? SendKit::apnsToken(1) : SendKit::fcmToken(1)], SendKit::live()));

    expect($result->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($transport->requests())->toHaveCount(1);
})->with(['apns', 'fcm']);

it('still plans a retry for a normal ttl(3600) alert after a 503', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::apnsReason(503, 'ServiceUnavailable'));
    $sender = $kit->sender($transport);

    [$long] = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('Sale', 'Soon')->ttl(3600), [SendKit::apnsToken(1)], SendKit::live()));

    expect($long->status)->toBe(SendStatus::Deferred)
        ->and($long->nextAttemptAt)->not->toBeNull()
        ->and((int) $transport->requests()[0]->headers['apns-expiration'])->toBeGreaterThan(1_000_000_000);
});
