<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * A subtitle has no FCM equivalent. Strict (the default), the message is refused for Android before the network;
 * lenient(), the subtitle is dropped for Android, the push is sent, and the caller reads why on the result.
 */
beforeEach(function () {
    $this->world = W::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $this->android = FcmToken::of('fake-android-registration-token-lenient');
});

it('sends a lenient subtitle to Android without it and reports the dropped field on that result', function () {
    $report = MobilePush::to($this->android)->send(PushMessage::make('Order shipped', 'On its way')->subtitle('Order #42')->lenient());
    $result = W::all($report)[0];

    $sent = $this->world->requestsTo('fcm.googleapis.com');
    $body = json_decode($sent[0]->body, true);
    expect($result->status)->toBe(SendStatus::Accepted)
        ->and($result->meta['warnings'] ?? null)->toContain('Dropped iOS-only capability "subtitle"')
        ->and($sent)->toHaveCount(1)
        ->and($body['message']['notification'])->toBe(['title' => 'Order shipped', 'body' => 'On its way'])
        // the same result, warnings included, is what PushResultProduced listeners receive
        ->and($this->world->eventsOf(PushResultProduced::class)[0]->result->meta['warnings'] ?? null)->toBe($result->meta['warnings']);
});

it('keeps the warning on the Android result only when the same lenient message also reaches an iPhone', function () {
    $iphone = ApnsDeviceToken::of(W::apnsToken('lenient-iphone'));

    $results = W::all(MobilePush::to([$iphone, $this->android])->send(PushMessage::make('Hi')->subtitle('Order #42')->lenient()));

    $apns = json_decode($this->world->requestsTo('api.push.apple.com')[0]->body, true);
    expect(array_map(static fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and($results[0]->meta)->not->toHaveKey('warnings')
        ->and($apns['aps']['alert']['subtitle'])->toBe('Order #42')
        ->and($results[1]->meta['warnings'] ?? null)->toContain('subtitle');
});

it('refuses the strict message for Android before the network', function () {
    $strict = PushMessage::make('Hi')->subtitle('Order #42');

    expect(fn () => MobilePush::to($this->android)->send($strict))->toThrow(UnsupportedCapabilityException::class)
        ->and($this->world->pushRequests())->toBeEmpty();

    // next to an iPhone it is not an exception: the Android destination is Rejected, the iPhone gets its subtitle
    $results = W::all(MobilePush::to([ApnsDeviceToken::of(W::apnsToken('strict-iphone')), $this->android])->send($strict));
    expect($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($results[1]->status)->toBe(SendStatus::Rejected)
        ->and($results[1]->reason->code)->toBe(ReasonCode::PayloadInvalid)
        ->and($this->world->requestsTo('fcm.googleapis.com'))->toBeEmpty()
        ->and($this->world->requestsTo('api.push.apple.com'))->toHaveCount(1);
});
