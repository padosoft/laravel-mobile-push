<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * "Two aliases, one Firebase project": the rate ceiling counts messages per provider ACCOUNT. Two connection names
 * pointing at the same Firebase project draw from one budget; an APNs connection has its own budget (its own
 * ceiling) and is not slowed down by Firebase traffic. Requests are counted by the transport.
 */
it('two aliases of one Firebase project share one rate budget, while APNs keeps its own', function () {
    $world = IntegrationWorld::install([
        'connections' => [
            'android-shop' => W::fcm(keyId: 'sa-key-1'),
            'android-promo' => W::fcm(keyId: 'sa-key-1'),
            'ios' => W::apns(),
        ],
        'guards.rate.fcm' => 3,
        'guards.rate.apns' => 3,
        'guards.rate.reserved_share' => 0.0,
    ]);
    $send = static fn (string $connection, $destination) => W::all(MobilePush::connection($connection)->to($destination)->send(PushMessage::make('Hi')))[0];

    $fcm = [];
    foreach (['android-shop', 'android-promo', 'android-shop', 'android-promo'] as $i => $connection) {
        $fcm[] = $send($connection, FcmToken::of('fake-registration-token-'.$i));
    }
    $apns = [];
    for ($i = 0; $i < 3; $i++) {
        $apns[] = $send('ios', ApnsDeviceToken::of(W::apnsToken('budget-'.$i)));
    }

    expect(array_map(static fn ($r) => $r->status, $fcm))->toBe([SendStatus::Accepted, SendStatus::Accepted, SendStatus::Accepted, SendStatus::Deferred])
        ->and($fcm[3]->reason->code)->toBe(ReasonCode::QuotaExceeded)
        ->and($world->send->requestsTo('fcm.googleapis.com'))->toHaveCount(3)
        ->and(array_map(static fn ($r) => $r->status, $apns))->toBe([SendStatus::Accepted, SendStatus::Accepted, SendStatus::Accepted])
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(3);
});

it('an APNs ceiling does not apply to Firebase: one unlimited provider is never slowed by the other', function () {
    $world = IntegrationWorld::install([
        'connections' => ['android' => W::fcm(), 'ios' => W::apns()],
        'guards.rate.fcm' => null,
        'guards.rate.apns' => 1,
        'guards.rate.reserved_share' => 0.0,
    ]);

    $apns = W::all(MobilePush::connection('ios')->to([ApnsDeviceToken::of(W::apnsToken('a')), ApnsDeviceToken::of(W::apnsToken('b'))])->send(PushMessage::make('Hi')));
    $fcm = W::all(MobilePush::connection('android')->to([FcmToken::of('fake-registration-token-a'), FcmToken::of('fake-registration-token-b')])->send(PushMessage::make('Hi')));

    expect(array_map(static fn ($r) => $r->status, $apns))->toBe([SendStatus::Accepted, SendStatus::Deferred])
        ->and(array_map(static fn ($r) => $r->status, $fcm))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and($world->send->requestsTo('fcm.googleapis.com'))->toHaveCount(2)
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1);
});
