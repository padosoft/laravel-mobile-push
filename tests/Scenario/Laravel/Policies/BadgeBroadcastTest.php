<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
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

require_once dirname(__DIR__).'/Integration/IntegrationWorld.php';

/*
 * Story (PLAN §8: the badge does not apply to topics and broadcasts): a user has two unread orders and a badge of 2.
 * The shop then sends an incrementBadge() push to an FCM topic, to an FCM condition and to an APNs broadcast channel.
 * Nobody owns those destinations, so no counter moves: the user's badge stays 2, and the payload the transport
 * receives carries no badge at all (and no trace of the instruction itself).
 */
function mpUserWithBadgeOfTwo(): array
{
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $world->send->withDevices();
    $user = Customer::withId(42);
    $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('iphone-42')), $user);
    $world->send->register(MobilePush::binding('android'), FcmToken::of('fake-android-token-42'), $user);
    foreach (['order-1', 'order-2'] as $effect) {
        W::all(MobilePush::to($user)->effectKey($effect)->send(PushMessage::make('New order')->incrementBadge()));
    }
    expect(app(PushManager::class)->currentBadge($user))->toBe(2);
    $world->send->transport->reset();

    return [$world, $user];
}

it('does not touch the owner badge and sends no badge to an FCM topic', function () {
    [$world, $user] = mpUserWithBadgeOfTwo();

    $results = W::all(MobilePush::connection('android')->toTopic('news')->effectKey('news-1')->send(PushMessage::make('Breaking', 'News')->incrementBadge()));

    $requests = $world->send->requestsTo('fcm.googleapis.com');
    expect($results)->toHaveCount(1)
        ->and($requests)->toHaveCount(1)
        ->and(IntegrationWorld::body($requests[0])['message']['topic'])->toBe('news')
        ->and($requests[0]->body)->not->toContain('notification_count')->not->toContain('badge')->not->toContain('incrementBadge')
        ->and(app(PushManager::class)->currentBadge($user))->toBe(2);
});

it('does not touch the owner badge and sends no badge to an FCM condition', function () {
    [$world, $user] = mpUserWithBadgeOfTwo();

    $results = W::all(MobilePush::connection('android')->toCondition("'news' in topics && 'sport' in topics")->effectKey('cond-1')->send(PushMessage::make('Breaking', 'News')->incrementBadge()));

    $requests = $world->send->requestsTo('fcm.googleapis.com');
    expect($results)->toHaveCount(1)
        ->and($requests)->toHaveCount(1)
        ->and(IntegrationWorld::body($requests[0])['message'])->toHaveKey('condition')
        ->and($requests[0]->body)->not->toContain('notification_count')->not->toContain('badge')->not->toContain('incrementBadge')
        ->and(app(PushManager::class)->currentBadge($user))->toBe(2);
});

it('does not touch the owner badge when an incrementBadge() push is aimed at an APNs broadcast channel: it never leaves, so no payload carries a badge', function () {
    [$world, $user] = mpUserWithBadgeOfTwo();

    // A broadcast channel only takes Live Activity updates; a PushMessage (the only message with incrementBadge)
    // compiles to an alert and is refused before any request. The counter must not move on the way.
    $results = W::all(MobilePush::connection('ios')->to(ApnsBroadcastChannel::of('dHN0LXNyY2gtY2hubA=='))->effectKey('chan-1')->send(PushMessage::make('Breaking', 'News')->incrementBadge()));

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(SendStatus::Rejected)
        ->and($results[0]->reason->code)->toBe(ReasonCode::RequestInvalid)
        ->and($world->send->pushRequests())->toBe([])
        ->and(app(PushManager::class)->currentBadge($user))->toBe(2);
});
