<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

function lostResponse(): TransportFailure
{
    return new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream 1 was not closed cleanly: INTERNAL_ERROR');
}

/*
 * Story: APNs accepted the alert, but the connection dropped before the 200 arrived. Sending it again could show
 * the alert twice, so the package reports that it does not know, and does nothing else.
 */
it('reports an alert whose response was lost as OutcomeUnknown, sent exactly once and never a fallback candidate', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(lostResponse());
    $sender = $kit->sender($transport, policy: new RetryPolicy(jitter: 0.0, inlineThresholdMs: 5_000));

    [$result] = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('Your code', '123456'), [SendKit::apnsToken(1)], SendKit::live()));

    expect($transport->requests())->toHaveCount(1)
        ->and($result->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($result->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($result->attempts)->toBe(1)
        ->and($result->status->allowsFallback())->toBeFalse()
        ->and($kit->sleeps)->toBe([]);
});

it('retries a lost Live Activity update once, because a later copy replaces the earlier one, and reports it accepted', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(lostResponse(), new Response(200));
    // A one-second backoff is short enough to wait in-process with this policy.
    $sender = $kit->sender($transport, policy: new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, inlineThresholdMs: 1000));
    $activity = ApnsLiveActivityToken::of(str_repeat('0f', 32));

    [$result] = SendKit::all($sender->send($kit->apnsDriver(), LiveActivityMessage::update(['eta' => 5]), [$activity], SendKit::live()));

    $requests = $transport->requests();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->headers['apns-id'])->toBe($requests[1]->headers['apns-id'])
        ->and($requests[1]->headers['apns-push-type'])->toBe('liveactivity')
        ->and($result->status)->toBe(SendStatus::Accepted)
        ->and($result->attempts)->toBe(2)
        ->and($kit->sleeps)->toBe([1000]);
});

it('defers the retry of a lost Live Activity update instead of sleeping when the backoff is above the inline threshold', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(lostResponse());
    $sender = $kit->sender($transport); // default inline threshold: 0 ms

    [$result] = SendKit::all($sender->send($kit->apnsDriver(), LiveActivityMessage::update(['eta' => 5]), [ApnsLiveActivityToken::of(str_repeat('0f', 32))], SendKit::live()));

    expect($transport->requests())->toHaveCount(1)
        ->and($result->status)->toBe(SendStatus::Deferred)
        ->and($result->nextAttemptAt)->toEqual($kit->clock->now()->modify('+1000 milliseconds'))
        ->and($kit->sleeps)->toBe([]);
});
