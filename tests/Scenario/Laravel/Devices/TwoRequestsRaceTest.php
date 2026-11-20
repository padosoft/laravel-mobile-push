<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;

require_once __DIR__.'/DeviceWorld.php';

/*
 * Two requests register the same token at once. The race is simulated for real: a second database connection
 * to the same SQLite file commits its row in the gap between the first request's lookup (nothing found) and its
 * INSERT. The unique index rejects the INSERT; the registrar must then treat it as a re-registration.
 */
beforeEach(function (): void {
    $this->world = DeviceWorld::install($this);
    $this->file = tempnam(sys_get_temp_dir(), 'mp-race-');
    foreach (['race_a', 'race_b'] as $name) {
        config()->set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => false]);
    }
    config()->set('mobile-push.devices.connection', 'race_a');
    (require DevicesModule::migrationPath())->up();
});

afterEach(function (): void {
    DB::purge('race_a');
    DB::purge('race_b');
    @unlink($this->file);
});

it('creates no duplicate row and loses no generation bump', function (bool $sameOwner, int $expectedGeneration, string $expectedOwner): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('race');
    $fired = false;

    PushDevice::creating(function () use (&$fired, $binding, $token, $sameOwner): void {
        if ($fired) {
            return;
        }
        $fired = true;

        // "Request B", on its own connection, wins the race.
        config()->set('mobile-push.devices.connection', 'race_b');
        app(DeviceRegistrar::class)->register($binding, $token, $sameOwner ? DeviceWorld::user(1) : DeviceWorld::user(2));
        config()->set('mobile-push.devices.connection', 'race_a');
    });

    $device = app(DeviceRegistrar::class)->register($binding, $token, DeviceWorld::user(1));

    $rows = DB::connection('race_a')->table('mobile_push_devices')->get();
    expect($fired)->toBeTrue()
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->generation)->toBe($expectedGeneration)
        ->and($rows[0]->owner_id)->toBe('1')
        ->and($device->generation)->toBe($expectedGeneration);
})->with([
    'same owner both times: one registration, generation 1' => [true, 1, '1'],
    'other owner won first: ours is a takeover, generation 2' => [false, 2, '1'],
]);
