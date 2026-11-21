<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;

require_once __DIR__.'/DeviceWorld.php';

/*
 * "The application registers the device inside its own transaction, and another request registers the same token
 * meanwhile." Server engines only (the MySQL / PostgreSQL CI jobs): request B is a real second connection that
 * commits while request A's transaction is open.
 *
 * At MySQL's default REPEATABLE READ, A's transaction answers plain reads from the snapshot it took at its first
 * read: after the unique index refuses A's INSERT, a plain lookup still does not see B's row. The registrar must
 * find it anyway and treat its own call as a re-registration.
 */
beforeEach(function (): void {
    $default = (string) config('database.default');
    if (! in_array(config("database.connections.{$default}.driver"), ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Needs a server database: two connections write at once.');
    }
    foreach (['race_a', 'race_b'] as $name) {
        config()->set("database.connections.{$name}", config("database.connections.{$default}"));
    }
    $this->world = DeviceWorld::install($this);
    config()->set('mobile-push.devices.connection', 'race_a');
});

afterEach(function (): void {
    DB::purge('race_a');
    DB::purge('race_b');
});

it('registers inside the caller\'s transaction while another request commits the same token: one row, no exception', function (bool $sameOwner, int $expectedGeneration): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('race');
    $fired = false;

    PushDevice::creating(function () use (&$fired, $binding, $token, $sameOwner): void {
        if ($fired) {
            return;
        }
        $fired = true;

        // "Request B", on its own connection, registers and commits first.
        config()->set('mobile-push.devices.connection', 'race_b');
        app(DeviceRegistrar::class)->register($binding, $token, $sameOwner ? DeviceWorld::user(1) : DeviceWorld::user(2));
        config()->set('mobile-push.devices.connection', 'race_a');
    });

    $device = DB::connection('race_a')->transaction(function () use ($binding, $token): PushDevice {
        // The application's own work comes first: this read is what fixes the snapshot at REPEATABLE READ.
        expect(DB::connection('race_a')->table('mobile_push_devices')->count())->toBe(0);

        return app(DeviceRegistrar::class)->register($binding, $token, DeviceWorld::user(1));
    });

    $rows = DB::connection('race_b')->table('mobile_push_devices')->get();
    expect($fired)->toBeTrue()
        ->and($rows)->toHaveCount(1)
        ->and((int) $rows[0]->generation)->toBe($expectedGeneration)
        ->and((string) $rows[0]->owner_id)->toBe('1')
        ->and($device->generation)->toBe($expectedGeneration);
})->with([
    'same owner both times: one registration, generation 1' => [true, 1],
    'other owner won first: ours is a takeover, generation 2' => [false, 2],
]);
