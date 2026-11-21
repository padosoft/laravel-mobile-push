<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Integration\ModuleMigrations;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;

/**
 * Device registry. TokenIndex and TokenCipher come from the Privacy module.
 *
 * Config (all read with defaults): mobile-push.devices.{enabled=false, table='mobile_push_devices',
 * connection=null, model=PushDevice::class}.
 */
final class DevicesModule implements Module
{
    public const PUBLISH_TAG = 'mobile-push-migrations-devices';

    public static function register(Application $app): void
    {
        $app->singleton(DeviceRegistrar::class, static fn (Application $app): DeviceRegistrar => new DeviceRegistrar(
            $app->make(TokenIndex::class), $app->make(TokenCipher::class), $app->make(ClockInterface::class),
        ));
        $app->singleton(DeviceOutcomeApplier::class, static fn (Application $app): DeviceOutcomeApplier => new DeviceOutcomeApplier($app->make(ClockInterface::class)));
        $app->singleton(DevicePruner::class, static fn (Application $app): DevicePruner => new DevicePruner($app->make(ClockInterface::class)));
        $app->singleton(DeviceEligibility::class, static fn (): DeviceEligibility => new DeviceEligibility);
    }

    public static function boot(Application $app): void
    {
        ModuleMigrations::wire($app, [self::migrationPath()], self::PUBLISH_TAG, (bool) config('mobile-push.devices.enabled', false));
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/2026_11_20_000001_create_mobile_push_devices_table.php';
    }
}
