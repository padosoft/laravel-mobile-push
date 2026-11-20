<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: a 3 KB promotion fits the 4096-byte limit of a token message but not the 2048-byte limit of a topic.
 */
it('rejects only the oversized topic and sends the valid tokens of the same batch', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $message = PushMessage::make('Promotion', str_repeat('Great deal! ', 250));

    $results = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), $message, [SendKit::fcmToken(1), FcmTopic::of('deals'), SendKit::fcmToken(2)], SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Rejected, SendStatus::Accepted])
        ->and($results[1]->reason->code)->toBe(ReasonCode::PayloadTooLarge)
        ->and($results[1]->reason->hint)->toContain('2048')
        ->and($results[1]->attempts)->toBe(0)
        ->and(array_map(fn (Request $r) => json_decode($r->body, true)['message']['token'] ?? null, $transport->requests()))
        ->toBe([SendKit::fcmToken(1)->value(), SendKit::fcmToken(2)->value()]);
});

it('throws for a globally invalid message before any request is sent', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $invalid = PushMessage::make('Visible title')->silent();   // a silent push cannot carry an alert

    expect(fn () => $kit->sender($transport)->send($kit->apnsDriver(), $invalid, SendKit::apnsTokens(5), SendKit::live()))
        ->toThrow(InvalidMessageException::class);
    expect($transport->requests())->toBe([]);
});

it('rejects a destination of the wrong kind without stopping the batch', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $activity = \Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken::of(str_repeat('0e', 32));

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [SendKit::apnsToken(1), $activity, SendKit::apnsToken(2)], SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Rejected, SendStatus::Accepted])
        ->and($results[1]->reason->code)->toBe(ReasonCode::RequestInvalid)
        ->and($transport->requests())->toHaveCount(2);
});

/*
 * Story: CI checks that the release notification is valid for FCM without notifying anyone.
 */
it('validates an FCM message without sending it: Validated, and the request carries validate_only', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());

    [$result] = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), PushMessage::make('v2.0', 'Released'), [SendKit::fcmToken(1)], SendKit::live(validateOnly: true)));

    $body = json_decode($transport->requests()[0]->body, true);
    expect($result->status)->toBe(SendStatus::Validated)
        ->and($result->status->isAccepted())->toBeFalse()
        ->and($body['validate_only'])->toBeTrue()
        ->and($transport->requests()[0]->headers['authorization'])->toBe('Bearer ya29.fake-access-token-one')
        ->and($transport->requests()[0]->url)->toBe('https://fcm.googleapis.com/v1/projects/'.SendKit::PROJECT.'/messages:send');
});

it('refuses validate-only on APNs, which has no dry run, before any request', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;

    expect(fn () => $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live(validateOnly: true)))
        ->toThrow(UnsupportedCapabilityException::class);
    expect($transport->requests())->toBe([]);
});
