<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Devices\DeviceChange;
use Padosoft\MobilePush\Laravel\Devices\DeviceOutcomeApplier;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;

require_once __DIR__.'/../../../Scenario/Laravel/Devices/DeviceWorld.php';

beforeEach(function (): void {
    $this->world = DeviceWorld::install($this);
    app(DeviceRegistrar::class)->register(DeviceWorld::apnsBinding(), DeviceWorld::apnsToken('t'), DeviceWorld::user(1));
    $this->registeredMs = PushDevice::msOf(PushDevice::query()->first()->registered_at);
});

// [row state, ref generation offset, status, action, timestamp offset to registered_at (ms) or null, expected change, expected final state]
dataset('applier table', [
    'quarantine active' => ['active', 0, SendStatus::Rejected, DestinationAction::Quarantine, null, DeviceChange::Quarantined, 'quarantined'],
    'invalidate active, FCM style (no timestamp)' => ['active', 0, SendStatus::Rejected, DestinationAction::Invalidate, null, DeviceChange::Invalidated, 'invalidated'],
    'invalidate, timestamp 1 ms after registration' => ['active', 0, SendStatus::Rejected, DestinationAction::Invalidate, 1, DeviceChange::Invalidated, 'invalidated'],
    'invalidate, timestamp equal to registration' => ['active', 0, SendStatus::Rejected, DestinationAction::Invalidate, 0, DeviceChange::StaleTimestamp, 'active'],
    'invalidate, timestamp before registration' => ['active', 0, SendStatus::Rejected, DestinationAction::Invalidate, -1, DeviceChange::StaleTimestamp, 'active'],
    'invalidate a quarantined row' => ['quarantined', 0, SendStatus::Rejected, DestinationAction::Invalidate, 5, DeviceChange::Invalidated, 'invalidated'],
    'quarantine an invalidated row' => ['invalidated', 0, SendStatus::Rejected, DestinationAction::Quarantine, null, DeviceChange::AlreadyInvalidated, 'invalidated'],
    'quarantine a quarantined row' => ['quarantined', 0, SendStatus::Rejected, DestinationAction::Quarantine, null, DeviceChange::AlreadyQuarantined, 'quarantined'],
    'invalidate an invalidated row' => ['invalidated', 0, SendStatus::Rejected, DestinationAction::Invalidate, 9, DeviceChange::AlreadyInvalidated, 'invalidated'],
    'older generation (late answer)' => ['active', -1, SendStatus::Rejected, DestinationAction::Invalidate, 9, DeviceChange::GenerationChanged, 'active'],
    'newer generation than the row' => ['active', 1, SendStatus::Rejected, DestinationAction::Quarantine, null, DeviceChange::GenerationChanged, 'active'],
    'unknown outcome with invalidate' => ['active', 0, SendStatus::OutcomeUnknown, DestinationAction::Invalidate, 9, DeviceChange::UnknownOutcome, 'active'],
    'unknown outcome with quarantine' => ['active', 0, SendStatus::OutcomeUnknown, DestinationAction::Quarantine, null, DeviceChange::UnknownOutcome, 'active'],
    'no action' => ['active', 0, SendStatus::Accepted, DestinationAction::None, null, DeviceChange::NoAction, 'active'],
]);

it('applies the generation/timestamp decision table', function (string $state, int $genOffset, SendStatus $status, DestinationAction $action, ?int $tsOffset, DeviceChange $expected, string $finalState): void {
    DB::table('mobile_push_devices')->update(['state' => $state, 'generation' => 5]);
    $id = (string) DB::table('mobile_push_devices')->value('id');

    $result = app(DeviceOutcomeApplier::class)->apply(
        new DestinationRef($id, 5 + $genOffset),
        $status,
        $action,
        $tsOffset === null ? null : $this->registeredMs + $tsOffset,
    );

    expect($result->change)->toBe($expected)
        ->and(DB::table('mobile_push_devices')->value('state'))->toBe($finalState)
        ->and(DB::table('mobile_push_devices')->value('generation'))->toBe(5);
})->with('applier table');

it('reports a missing row', function (): void {
    $result = app(DeviceOutcomeApplier::class)->apply(new DestinationRef('999999', 1), SendStatus::Rejected, DestinationAction::Invalidate);

    expect($result->change)->toBe(DeviceChange::RowMissing);
});
