<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

final class OrderShippedNotification extends Notification
{
    public function __construct(public readonly string $order) {}
}

/** A notifiable that routes push by the notification it is asked about (Laravel's convention). */
final class RoutingByNotification
{
    /** @var list<?Notification> */
    public array $askedFor = [];

    public function routeNotificationForPush(?Notification $notification): array
    {
        $this->askedFor[] = $notification;

        return $notification instanceof OrderShippedNotification ? [ApnsDeviceToken::of(W::apnsToken('route-'.$notification->order))] : [];
    }
}

/*
 * The manager speaks Laravel's notification routing: Notification::route('push', ...) anonymous notifiables, the
 * notification handed to routeNotificationForPush(), and a send restricted to one provider.
 */
it('sends to an anonymous notifiable routed with Notification::route("push", ...) and to nothing when it has no push route', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $anonymous = NotificationFacade::route('push', [ApnsDeviceToken::of(W::apnsToken('anon')), FcmToken::of('fake-anon-android-token')]);
    expect($anonymous)->toBeInstanceOf(AnonymousNotifiable::class);

    $results = W::all(MobilePush::to($anonymous)->send(PushMessage::make('Hi')));
    $nothing = MobilePush::to(NotificationFacade::route('mail', 'someone@example.com'))->send(PushMessage::make('Hi'));

    expect(array_map(static fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and($nothing->count())->toBe(0)
        ->and($world->send->pushRequests())->toHaveCount(2);
});

it('hands the notification to the notifiable route (forNotification)', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $notifiable = new RoutingByNotification;
    $notification = new OrderShippedNotification('A-17');

    $results = W::all(MobilePush::to($notifiable)->forNotification($notification)->send(PushMessage::make('Shipped')));
    $withoutNotification = MobilePush::to($notifiable)->send(PushMessage::make('Shipped'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->destination->value())->toBe(W::apnsToken('route-A-17'))
        ->and($notifiable->askedFor[0])->toBe($notification)
        ->and($withoutNotification->count())->toBe(0)
        ->and($world->send->pushRequests())->toHaveCount(1);
});

it('restricts a send to one provider: the other provider is left out, not reported', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $world->send->withDevices();
    $user = Customer::withId(3);
    $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('iphone-3')), $user);
    $world->send->register(MobilePush::binding('android'), FcmToken::of('fake-android-token-3'), $user);

    $apnsOnly = W::all(MobilePush::to($user)->to(FcmToken::of('fake-raw-android'))->onlyProvider(Provider::Apns)->send(PushMessage::make('Hi')));
    $fcmOnly = W::all(MobilePush::to($user)->onlyProvider(Provider::Fcm)->send(PushMessage::make('Hi')));
    $namedOther = MobilePush::connection('ios')->to($user)->onlyProvider(Provider::Fcm)->send(PushMessage::make('Hi'));

    expect(array_map(static fn ($r) => $r->destination->kind()->value, $apnsOnly))->toBe(['apns_device_token'])
        ->and(array_map(static fn ($r) => $r->destination->kind()->value, $fcmOnly))->toBe(['fcm_token'])
        ->and($namedOther->count())->toBe(0)
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and($world->send->requestsTo('fcm.googleapis.com'))->toHaveCount(1);
});
