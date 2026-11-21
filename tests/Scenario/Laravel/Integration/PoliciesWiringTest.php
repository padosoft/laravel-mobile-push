<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * Policies wired into the manager: a person with an iPhone and an Android phone. The frequency cap is decided once
 * per person and effect (both phones get the first marketing push, both skip the second); the server-side badge
 * gives both phones the same number for each effect; nothing is capped while the cap is off (the default).
 */
function mpTwoPhones(array $config): array
{
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]] + $config);
    $world->send->withDevices();
    $user = Customer::withId(42);
    $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('iphone-42')), $user);
    $world->send->register(MobilePush::binding('android'), FcmToken::of('fake-android-token-42'), $user);

    return [$world, $user];
}

/** @return array{apns: ?int, fcm: ?int} the badge numbers of the last APNs and FCM requests */
function mpBadges(IntegrationWorld $world): array
{
    $apns = $world->send->requestsTo('api.push.apple.com');
    $fcm = $world->send->requestsTo('fcm.googleapis.com');
    $apnsBody = IntegrationWorld::body(end($apns));
    $fcmBody = IntegrationWorld::body(end($fcm));

    return [
        'apns' => $apnsBody['aps']['badge'] ?? null,
        'fcm' => $fcmBody['message']['android']['notification']['notification_count'] ?? null,
    ];
}

it('caps a person once per effect: both phones get the first marketing push, both skip the second', function () {
    [$world, $user] = mpTwoPhones(['frequency.enabled' => true, 'frequency.limits' => ['marketing' => ['per_day' => 1]]]);

    $first = W::all(MobilePush::to($user)->send(PushMessage::make('Sale')->policy('marketing')));
    $second = W::all(MobilePush::to($user)->send(PushMessage::make('Another sale')->policy('marketing')));

    expect(array_map(static fn ($r) => $r->status, $first))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and(array_map(static fn ($r) => $r->status, $second))->toBe([SendStatus::Skipped, SendStatus::Skipped])
        ->and(array_map(static fn ($r) => $r->reason->code, $second))->toBe([ReasonCode::PolicyFrequencyCapped, ReasonCode::PolicyFrequencyCapped])
        ->and($world->send->pushRequests())->toHaveCount(2);
});

it('does not cap anything while the frequency cap is off (the default)', function () {
    [$world, $user] = mpTwoPhones([]);

    foreach (range(1, 4) as $i) {
        expect(array_map(static fn ($r) => $r->status, W::all(MobilePush::to($user)->send(PushMessage::make('Sale '.$i)->policy('marketing')))))
            ->toBe([SendStatus::Accepted, SendStatus::Accepted]);
    }
    expect($world->send->pushRequests())->toHaveCount(8);
});

it('shows the same badge number on both phones for each effect, and starts again from 1 after the reset', function () {
    [$world, $user] = mpTwoPhones([]);

    $seen = [];
    foreach (['order-1', 'order-2', 'order-3'] as $effect) {
        W::all(MobilePush::to($user)->effectKey($effect)->send(PushMessage::make('New message')->incrementBadge()));
        $seen[] = mpBadges($world);
    }
    // A retry of the same effect does not count again.
    W::all(MobilePush::to($user)->effectKey('order-3')->send(PushMessage::make('New message')->incrementBadge()));
    $seen[] = mpBadges($world);

    app(PushManager::class)->resetBadge($user);
    W::all(MobilePush::to($user)->send(PushMessage::make('After opening the app')->incrementBadge()));
    $seen[] = mpBadges($world);

    expect($seen)->toBe([
        ['apns' => 1, 'fcm' => 1],
        ['apns' => 2, 'fcm' => 2],
        ['apns' => 3, 'fcm' => 3],
        ['apns' => 3, 'fcm' => 3],
        ['apns' => 1, 'fcm' => 1],
    ])->and(app(PushManager::class)->currentBadge($user))->toBe(1);
});

it('leaves an explicit badge and an ownerless destination untouched', function () {
    [$world, $user] = mpTwoPhones([]);

    W::all(MobilePush::to($user)->send(PushMessage::make('Hi')->badge(9)));
    expect(mpBadges($world))->toBe(['apns' => 9, 'fcm' => 9])
        ->and(app(PushManager::class)->currentBadge($user))->toBe(0);
    $world->send->transport->reset();

    W::all(MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('no-owner')))->send(PushMessage::make('Hi')->incrementBadge()));
    $ownerless = IntegrationWorld::body($world->send->requestsTo('api.push.apple.com')[0]);

    expect($ownerless['aps'])->not->toHaveKey('badge');
});
