<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Notifications\FallbackEvaluator;
use Padosoft\MobilePush\Laravel\Notifications\PushChannel;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\OrderShipped;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\Shopper;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\NotificationsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

beforeEach(function () {
    $this->world = NotificationsWorld::install();
    $this->user = Shopper::withId(1);
});

it('order shipped: one notify() reaches the iPhone through APNs and the Android phone through FCM', function () {
    $iphone = $this->world->iphone($this->user);
    $android = $this->world->android($this->user);
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$sent): void {
        $sent[] = $e;
    });

    $this->user->notify(new OrderShipped);

    $apns = $this->world->push->requestsTo('api.push.apple.com');
    $fcm = $this->world->push->requestsTo('fcm.googleapis.com');
    expect($apns)->toHaveCount(1)
        ->and($apns[0]->url)->toEndWith('/3/device/'.$iphone)
        ->and(json_decode($apns[0]->body, true)['aps']['alert'])->toBe(['title' => 'Order shipped', 'body' => 'Arrives Friday'])
        ->and(json_decode($apns[0]->body, true)['order'])->toBe('42')
        ->and($fcm)->toHaveCount(1)
        ->and(json_decode($fcm[0]->body, true)['message']['token'])->toBe($android)
        ->and(json_decode($fcm[0]->body, true)['message']['notification']['title'])->toBe('Order shipped')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->channel)->toBe('push')
        ->and($sent[0]->response)->toBeInstanceOf(SendReport::class)
        ->and($sent[0]->response->countOf(SendStatus::Accepted))->toBe(2);
});

it('native channels: apns reaches only the iPhone and fcm only the Android phone', function () {
    $this->world->iphone($this->user);
    $this->world->android($this->user);

    $this->user->notify(new OrderShipped(['apns']));
    expect($this->world->push->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and($this->world->push->requestsTo('fcm.googleapis.com'))->toBeEmpty()
        ->and(json_decode($this->world->push->requestsTo('api.push.apple.com')[0]->body, true)['aps']['alert']['title'])->toBe('Order shipped (iOS)');

    $this->world->push->transport->reset();
    $this->user->notify(new OrderShipped(['fcm']));
    expect($this->world->push->requestsTo('api.push.apple.com'))->toBeEmpty()
        ->and($this->world->push->requestsTo('fcm.googleapis.com'))->toHaveCount(1)
        ->and(json_decode($this->world->push->requestsTo('fcm.googleapis.com')[0]->body, true)['message']['notification']['title'])->toBe('Order shipped (Android)');
});

it('tenant and connection hooks: pushScope() and pushConnection() route to the right app', function () {
    MobilePush::register('main', W::apns(keyId: 'KEYTENANTA', bundle: 'com.tenant-a.app'), 'tenant-a');
    MobilePush::register('main', W::apns(keyId: 'KEYTENANTB', bundle: 'com.tenant-b.app'), 'tenant-b');
    $this->world->iphone($this->user, 'phone-a', 'main', 'tenant-a');
    $this->world->iphone($this->user, 'phone-b', 'main', 'tenant-b');

    $this->user->notify(new OrderShipped(['push'], scope: 'tenant-b', app: 'main'));

    $requests = $this->world->push->requestsTo('api.push.apple.com');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->headers['apns-topic'])->toBe('com.tenant-b.app')
        ->and($requests[0]->url)->toEndWith(W::apnsToken('phone-b'))
        ->and(W::jwtHeader($requests[0])['kid'])->toBe('KEYTENANTB');
});

it('a user without devices produces an empty report and no error', function () {
    $report = (new PushChannel(
        app(PushManager::class),
        app(FallbackEvaluator::class),
    ))->send($this->user, new OrderShipped);

    expect($report->count())->toBe(0)
        ->and($this->world->push->transport->sentCount())->toBe(0);
});

it('a notification that cannot build a push message fails loudly before the network', function () {
    $this->world->iphone($this->user);
    $notification = new class extends Notification
    {
        public function via(mixed $notifiable): array
        {
            return ['push'];
        }
    };

    expect(fn () => $this->user->notify($notification))->toThrow(InvalidMessageException::class, 'toPush');
    expect($this->world->push->transport->sentCount())->toBe(0);
});
