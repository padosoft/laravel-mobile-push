<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\DeviceOutcomeApplier;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\DeviceState;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/../Sending/SendingWorld.php';

const PREFER_TOKEN = 'fake-android-registration-token-1';
const PREFER_FID = 'c1xyz_AbCdEfGhIjKlMnOp';

beforeEach(function (): void {
    $this->world = W::install(['connections' => ['android' => W::fcm()]])->withDevices();
    $this->user = Customer::withId(1);
    $this->register = fn (array $attributes = [], ?FcmToken $token = null) => app(DeviceRegistrar::class)
        ->register(MobilePush::binding('android'), $token ?? FcmToken::of(PREFER_TOKEN), $this->user, $attributes);
    $this->targets = fn (): array => array_map(
        static fn (Request $r): array => (array) (json_decode($r->body, true)['message'] ?? []),
        $this->world->requestsTo('fcm.googleapis.com'),
    );
});

it('sends a row with token and FID to the token by default, and to the FID when fcm_prefer is "fid": one request either way', function (string $setting, string $field, string $value, DestinationKind $kind): void {
    if ($setting !== '') {
        config()->set('mobile-push.devices.fcm_prefer', $setting);
    }
    $device = ($this->register)(['fid' => FcmFid::of(PREFER_FID)]);

    $report = MobilePush::to($this->user)->send(PushMessage::make('Order shipped'));
    $results = W::all($report);

    $sent = ($this->targets)();
    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($results[0]->destination->kind())->toBe($kind)
        ->and($results[0]->destination->value())->toBe($value)
        ->and($results[0]->destination->ref()?->id)->toBe((string) $device->id)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0][$field])->toBe($value)
        ->and($sent[0])->not->toHaveKey($field === 'fid' ? 'token' : 'fid')
        ->and($device->fresh()->last_sent_at)->not->toBeNull();
})->with([
    'default' => ['', 'token', PREFER_TOKEN, DestinationKind::FcmToken],
    'token' => ['token', 'token', PREFER_TOKEN, DestinationKind::FcmToken],
    'fid' => ['fid', 'fid', PREFER_FID, DestinationKind::FcmFid],
]);

it('a row that holds only one of the two is always sent to what it has, under both settings', function (string $setting): void {
    config()->set('mobile-push.devices.fcm_prefer', $setting);
    ($this->register)();                                                              // token only
    app(DeviceRegistrar::class)
        ->register(MobilePush::binding('android'), FcmFid::of('zzzzzzzzzzzzzzzzzzzzzz'), $this->user);   // FID only

    $results = W::all(MobilePush::to($this->user)->send(PushMessage::make('Hi')));

    $sent = ($this->targets)();
    expect($results)->toHaveCount(2)
        ->and(array_map(static fn ($r) => $r->destination->kind(), $results))->toBe([DestinationKind::FcmToken, DestinationKind::FcmFid])
        ->and($sent)->toHaveCount(2)
        ->and($sent[0]['token'] ?? null)->toBe(PREFER_TOKEN)
        ->and($sent[1]['fid'] ?? null)->toBe('zzzzzzzzzzzzzzzzzzzzzz');
})->with(['token', 'fid']);

it('FCM answers UNREGISTERED for the chosen FID: the whole installation row is invalidated, once', function (): void {
    config()->set('mobile-push.devices.fcm_prefer', 'fid');
    $device = ($this->register)(['fid' => FcmFid::of(PREFER_FID)]);
    $this->world->answer = fn (Request $r): ?Response => str_contains($r->body, PREFER_FID)
        ? new Response(404, [], (string) json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]))
        : null;

    $result = W::all(MobilePush::to($this->user)->send(PushMessage::make('Hi')))[0];

    expect($result->status)->toBe(SendStatus::Rejected)
        ->and($result->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($result->destination->kind())->toBe(DestinationKind::FcmFid)
        ->and($device->fresh()->state)->toBe(DeviceState::Invalidated)
        ->and(MobilePush::to($this->user)->send(PushMessage::make('again'))->count())->toBe(0)
        ->and($this->world->requestsTo('fcm.googleapis.com'))->toHaveCount(1);
});

it('a FID destination taken earlier stays tied to the row: eligibility and generation follow the same reference', function (): void {
    config()->set('mobile-push.devices.fcm_prefer', 'fid');
    $device = ($this->register)(['fid' => FcmFid::of(PREFER_FID)]);

    $destination = $this->user->pushDestinations()[0];
    $ref = $destination->ref();

    expect($destination->kind())->toBe(DestinationKind::FcmFid)
        ->and($this->user->pushDestinations())->toHaveCount(1)
        ->and($ref?->id)->toBe((string) $device->id)
        ->and($ref?->generation)->toBe($device->generation)
        ->and(app(DeviceEligibility::class)->check($ref)->eligible)->toBeTrue()
        ->and(app(DeviceOutcomeApplier::class)->markSent($ref))->toBeTrue();

    // The phone changes hands: new owner, new generation. The FID destination taken before is dead.
    app(DeviceRegistrar::class)
        ->register(MobilePush::binding('android'), FcmToken::of(PREFER_TOKEN), Customer::withId(2));

    expect(app(DeviceEligibility::class)->check($ref)->eligible)->toBeFalse()
        ->and(W::all(MobilePush::to([$destination])->send(PushMessage::make('Hi')))[0]->status)->toBe(SendStatus::Skipped)
        ->and($this->world->requestsTo('fcm.googleapis.com'))->toBeEmpty();
});

it('an invalid fcm_prefer is a configuration error raised before any request', function (string $bad): void {
    config()->set('mobile-push.devices.fcm_prefer', $bad);
    ($this->register)(['fid' => FcmFid::of(PREFER_FID)]);

    expect(fn () => MobilePush::to($this->user)->send(PushMessage::make('Hi'))->count())->toThrow(ConfigurationException::class)
        ->and(fn () => PushDevice::query()->firstOrFail()->destination())->toThrow(ConfigurationException::class)
        ->and($this->world->pushRequests())->toBeEmpty();
})->with(['FID', 'both', '']);
