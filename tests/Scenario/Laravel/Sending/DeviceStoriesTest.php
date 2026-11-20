<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Devices\DeviceState;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\Events\DestinationInvalidated;
use Padosoft\MobilePush\Laravel\Sending\Events\DestinationQuarantined;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

beforeEach(function () {
    $this->world = W::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]])->withDevices();
});

it('sends one report with two accepted results through two connections for a user with an iPhone and an Android phone', function () {
    $user = Customer::withId(1);
    $this->world->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('iphone')), $user);
    $this->world->register(MobilePush::binding('android'), FcmToken::of('fake-android-registration-token-1'), $user);

    $report = MobilePush::to($user)->send(PushMessage::make('Order shipped'));
    $results = W::all($report);

    expect($results)->toHaveCount(2)
        ->and(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and(array_map(fn ($r) => $r->destination->kind()->value, $results))->toBe(['apns_device_token', 'fcm_token'])
        ->and($this->world->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and($this->world->requestsTo('fcm.googleapis.com'))->toHaveCount(1)
        ->and(PushDevice::query()->whereNotNull('last_sent_at')->count())->toBe(2);
});

it('sends nothing to a phone that changed hands after the destination list was taken', function () {
    $alice = Customer::withId(1);
    $bob = Customer::withId(2);
    $token = ApnsDeviceToken::of(W::apnsToken('shared-phone'));
    $this->world->register(MobilePush::binding('ios'), $token, $alice);

    $alicesPhones = $alice->pushDestinations();                     // taken while Alice owns it (a queued job would carry this)
    $this->world->register(MobilePush::binding('ios'), $token, $bob); // the phone is now Bob's

    $results = W::all(MobilePush::to($alicesPhones)->send(PushMessage::make('Your private order')));

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(SendStatus::Skipped)
        ->and($results[0]->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($this->world->pushRequests())->toBeEmpty()
        ->and(MobilePush::to($alice)->send(PushMessage::make('again'))->count())->toBe(0);
});

it('stops a push to a user who opted out after it was prepared, even with bypassFrequencyCap', function () {
    $optedOut = W::apnsToken('opted-out');
    MobilePush::eligibleUsing(fn (Destination $destination, GateContext $context): bool => $destination->value() !== $optedOut);
    $tokens = [W::apnsToken('a'), $optedOut, W::apnsToken('b')];

    $first = W::all(MobilePush::toTokens(Platform::Ios, $tokens)->send(PushMessage::make('Sale')));
    $bypass = W::all(MobilePush::toTokens(Platform::Ios, $tokens)->send(PushMessage::make('Sale')->bypassFrequencyCap()));

    foreach ([$first, $bypass] as $results) {
        expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Skipped, SendStatus::Accepted])
            ->and($results[1]->reason->code)->toBe(ReasonCode::PolicyIneligible);
    }
    $sentTo = array_map(fn (Request $r) => substr($r->url, -64), $this->world->pushRequests());
    expect($sentTo)->not->toContain($optedOut)->and($sentTo)->toHaveCount(4);
});

it('invalidates the row of an uninstalled app on a later 410 and dispatches DestinationInvalidated', function () {
    $user = Customer::withId(1);
    $device = $this->world->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('uninstalled')), $user);
    $later = ($this->world->clock->now()->getTimestamp() + 60) * 1000;
    $this->world->answer = fn (Request $r): Response => new Response(410, ['apns-id' => '11111111-2222-4333-8444-555555555555'], json_encode(['reason' => 'Unregistered', 'timestamp' => $later]));

    $result = W::all(MobilePush::to($user)->send(PushMessage::make('Hi')))[0];

    $events = $this->world->eventsOf(DestinationInvalidated::class);
    expect($result->status)->toBe(SendStatus::Rejected)
        ->and($result->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($device->fresh()->state)->toBe(DeviceState::Invalidated)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->registryChange)->toBe('invalidated')
        ->and($events[0]->destination->ref?->id)->toBe((string) $device->id)
        ->and($this->world->eventsOf(PushResultProduced::class))->toHaveCount(1);
});

it('quarantines, and never deletes, a device token the provider calls bad', function () {
    $user = Customer::withId(1);
    $device = $this->world->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('sandbox-build')), $user);
    $this->world->answer = fn (Request $r): Response => new Response(400, [], json_encode(['reason' => 'BadDeviceToken']));

    $result = W::all(MobilePush::to($user)->send(PushMessage::make('Hi')))[0];

    expect($result->reason->code)->toBe(ReasonCode::DestinationRejected)
        ->and($device->fresh())->not->toBeNull()
        ->and($device->fresh()->state)->toBe(DeviceState::Quarantined)
        ->and($this->world->eventsOf(DestinationQuarantined::class))->toHaveCount(1)
        ->and($this->world->eventsOf(DestinationQuarantined::class)[0]->registryChange)->toBe('quarantined')
        ->and(MobilePush::to($user)->send(PushMessage::make('again'))->count())->toBe(0);
});
