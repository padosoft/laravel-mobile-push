<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * The server-side badge counts what somebody will receive: an owner whose every device is refused by the application's
 * eligibility check does not consume an increment; an owner with one allowed device does, once, for all its devices.
 */
function mpBadgeOwner(IntegrationWorld $world, int $id): Customer
{
    $owner = Customer::withId($id);
    $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('iphone-'.$id)), $owner);
    $world->send->register(MobilePush::binding('android'), FcmToken::of('fake-android-token-'.$id), $owner);

    return $owner;
}

function mpBadgeWorld(): IntegrationWorld
{
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $world->send->withDevices();

    return $world;
}

it('does not consume a badge increment for an owner every device of which is refused', function () {
    $world = mpBadgeWorld();
    $user = mpBadgeOwner($world, 42);
    MobilePush::eligibleUsing(static fn (Destination $d): bool => false);

    $results = W::all(MobilePush::to($user)->effectKey('order-1')->send(PushMessage::make('New message')->incrementBadge()));

    expect(array_map(static fn ($r) => $r->status, $results))->toBe([SendStatus::Skipped, SendStatus::Skipped])
        ->and(array_map(static fn ($r) => $r->reason->code, $results))->toBe([ReasonCode::PolicyIneligible, ReasonCode::PolicyIneligible])
        ->and($world->send->pushRequests())->toBe([])
        ->and(MobilePush::currentBadge($user))->toBe(0);
});

it('increments once for an owner with one allowed and one refused device, and the allowed one shows the number', function () {
    $world = mpBadgeWorld();
    $user = mpBadgeOwner($world, 42);
    $android = 'fake-android-token-42';
    MobilePush::eligibleUsing(static fn (Destination $d): bool => ! ($d instanceof FcmToken && $d->value() === $android));

    $results = W::all(MobilePush::to($user)->effectKey('order-1')->send(PushMessage::make('New message')->incrementBadge()));
    $requests = $world->send->pushRequests();

    expect(array_map(static fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Skipped])
        ->and($requests)->toHaveCount(1)
        ->and(IntegrationWorld::body($requests[0])['aps']['badge'])->toBe(1)
        ->and(MobilePush::currentBadge($user))->toBe(1);
});

it('counts only the owners somebody will receive the push for, in one send', function () {
    $world = mpBadgeWorld();
    $opted = mpBadgeOwner($world, 1);
    $optedOut = mpBadgeOwner($world, 2);
    $blockedTokens = [W::apnsToken('iphone-2'), 'fake-android-token-2'];
    MobilePush::eligibleUsing(static fn (Destination $d): bool => ! in_array($d->value(), $blockedTokens, true));

    foreach ([$opted, $optedOut] as $owner) {
        W::all(MobilePush::to($owner)->effectKey('promo-1')->send(PushMessage::make('Sale')->incrementBadge()));
    }

    expect(MobilePush::currentBadge($opted))->toBe(1)
        ->and(MobilePush::currentBadge($optedOut))->toBe(0)
        ->and($world->send->pushRequests())->toHaveCount(2);
});

it('keeps counting as before for owners nobody refuses (the numbers 1, 2, 3 on each phone)', function () {
    $world = mpBadgeWorld();
    $user = mpBadgeOwner($world, 42);
    MobilePush::eligibleUsing(static fn (Destination $d): bool => true);

    $seen = [];
    foreach (['a', 'b', 'c'] as $effect) {
        W::all(MobilePush::to($user)->effectKey($effect)->send(PushMessage::make('New message')->incrementBadge()));
        $apns = $world->send->requestsTo('api.push.apple.com');
        $seen[] = IntegrationWorld::body(end($apns))['aps']['badge'];
    }

    expect($seen)->toBe([1, 2, 3]);
});
