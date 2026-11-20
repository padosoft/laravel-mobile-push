<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

/*
 * Story: the app asks every device to refresh its contacts silently. An FCM token does not say whether it belongs to
 * an Android or an iOS install, so the message must carry what iOS needs to wake the app (Discovery: data-only
 * messages to iOS allow only apns-priority 5), and Android simply ignores it.
 */
it('adds the APNs background settings to a silent message so an iOS install wakes up', function () {
    $json = FcmKit::decode(FcmKit::compile(PushMessage::make()->silent()->data(['sync' => 'contacts'])))['message'];

    expect($json['apns']['headers'])->toBe(['apns-priority' => '5', 'apns-push-type' => 'background'])
        ->and($json['apns']['payload'])->toBe(['aps' => ['content-available' => 1]])
        ->and($json)->not->toHaveKey('notification');
});

it('leaves a visible message without an apns block', function () {
    $json = FcmKit::decode(FcmKit::compile(PushMessage::make('Hi', 'there')))['message'];

    expect($json)->not->toHaveKey('apns');
});

it('lets an apns block set through the FCM overlay win over the silent default', function () {
    $message = PushMessage::make()->silent()->data(['sync' => 'contacts'])
        ->fcm(fn (FcmMessage $m) => $m->apns(fn ($a) => $a->header('apns-priority', '5')));

    $json = FcmKit::decode(FcmKit::compile($message))['message'];

    expect($json['apns'])->toBe(['headers' => ['apns-priority' => '5']]);
});
