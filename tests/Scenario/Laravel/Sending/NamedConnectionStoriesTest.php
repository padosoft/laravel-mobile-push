<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * A named connection and the device registry. Two APNs apps of the same provider ("ios-a" = com.example.a with key
 * KEYAAAAAAA, "ios-b" = com.example.b with key KEYBBBBBBB) and one FCM app. Alice has an iPhone registered on ios-a
 * and an Android phone registered on android.
 */
beforeEach(function () {
    $this->world = W::install(['connections' => [
        'ios-a' => W::apns(keyId: 'KEYAAAAAAA', bundle: 'com.example.a'),
        'ios-b' => W::apns(keyId: 'KEYBBBBBBB', bundle: 'com.example.b'),
        'android' => W::fcm(),
    ]])->withDevices();
    $this->alice = Customer::withId(1);
    $this->iphone = ApnsDeviceToken::of(W::apnsToken('alice-iphone'));
    $this->world->register(MobilePush::binding('ios-a'), $this->iphone, $this->alice);
    $this->world->register(MobilePush::binding('android'), FcmToken::of('fake-alice-android-token'), $this->alice);
});

it('narrows a notifiable to the devices registered on the named connection, and sends nothing for an owner with none there', function () {
    $android = W::all(MobilePush::connection('android')->to($this->alice)->send(PushMessage::make('Android only')));
    $bob = Customer::withId(2);
    $nothingForBob = MobilePush::connection('ios-b')->to([$this->alice, $bob])->send(PushMessage::make('B app'));

    expect(array_map(static fn ($r) => $r->destination->kind()->value, $android))->toBe(['fcm_token'])
        ->and($android[0]->status)->toBe(SendStatus::Accepted)
        // nobody has a device on ios-b: an empty report, not an exception and not a Skipped per user
        ->and($nothingForBob->count())->toBe(0)
        ->and($this->world->requestsTo('api.push.apple.com'))->toBeEmpty()
        ->and($this->world->requestsTo('fcm.googleapis.com'))->toHaveCount(1);
});

it('never sends a device registered on app A through the connection of app B of the same provider, and says why', function () {
    $alicesPhones = $this->alice->pushDestinations('ios-a');      // registry destinations carrying their reference

    $results = W::all(MobilePush::connection('ios-b')->to($alicesPhones)->send(PushMessage::make('Wrong app')));

    // nothing reached APNs, with B's credentials or any other
    expect($this->world->requestsTo('api.push.apple.com'))->toBeEmpty()
        ->and($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(SendStatus::Skipped)
        ->and($results[0]->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($results[0]->reason->hint)->toContain('"ios-a"')
        ->and($results[0]->attempts)->toBe(0);

    // through its own connection the same reference is delivered with A's topic and key
    $own = W::all(MobilePush::connection('ios-a')->to($alicesPhones)->send(PushMessage::make('Right app')));
    $sent = $this->world->requestsTo('api.push.apple.com');
    expect($own[0]->status)->toBe(SendStatus::Accepted)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->headers['apns-topic'])->toBe('com.example.a')
        ->and(W::jwtHeader($sent[0])['kid'])->toBe('KEYAAAAAAA');
});

it('still sends a bare token (no registry reference) through whichever connection of its provider is named', function () {
    $bare = ApnsDeviceToken::of(W::apnsToken('alice-iphone'));    // same bytes, no DestinationRef

    $result = W::all(MobilePush::connection('ios-b')->to($bare)->send(PushMessage::make('Caller decides')))[0];
    $sent = $this->world->requestsTo('api.push.apple.com');

    expect($result->status)->toBe(SendStatus::Accepted)
        ->and(array_map(static fn (Request $r) => $r->headers['apns-topic'], $sent))->toBe(['com.example.b']);
});

it('refuses a device of another tenant sent through a connection of the same name, without echoing the other tenant', function () {
    MobilePush::register('ios-a', W::apns(keyId: 'KEYTENANT1', bundle: 'com.tenant.app'), 'tenant-x');
    $this->world->register(MobilePush::binding('ios-a', 'tenant-x'), ApnsDeviceToken::of(W::apnsToken('tenant-phone')), $this->alice);
    $tenantPhones = $this->alice->pushDestinations('ios-a', 'tenant-x');

    $result = W::all(MobilePush::connection('ios-a')->to($tenantPhones)->send(PushMessage::make('Wrong tenant')))[0];

    expect($this->world->requestsTo('api.push.apple.com'))->toBeEmpty()
        ->and($result->status)->toBe(SendStatus::Skipped)
        ->and($result->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($result->reason->hint)->toContain('another scope')
        ->and($result->reason->hint)->not->toContain('tenant-x');
});

it('narrows a notifiable that routes its own destinations, but refuses an explicit destination of the other provider', function () {
    $routed = new class
    {
        public function routeNotificationForPush(): array
        {
            return [ApnsDeviceToken::of(W::apnsToken('routed-iphone')), FcmToken::of('fake-routed-android-token')];
        }
    };

    $results = W::all(MobilePush::connection('android')->to([$routed, $routed])->send(PushMessage::make('Android only')));

    expect(array_map(static fn ($r) => $r->destination->kind()->value, $results))->toBe(['fcm_token', 'fcm_token'])
        ->and($this->world->requestsTo('api.push.apple.com'))->toBeEmpty()
        ->and(fn () => MobilePush::connection('android')->to(ApnsDeviceToken::of(W::apnsToken('explicit')))->send(PushMessage::make('x')))
        ->toThrow(InvalidDestinationException::class, 'cannot be sent through the fcm connection "android"');
});
