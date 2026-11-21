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

    public const MIGRATIONS = [
        '2026_11_20_000001_create_mobile_push_devices_table.php',
        // W5-P: keyset index of the campaign device audience (scope, binding_identity, state, id).
        '2026_11_21_000002_add_mobile_push_devices_audience_index.php',
    ];

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
        ModuleMigrations::wire($app, self::migrationPaths(), self::PUBLISH_TAG, (bool) config('mobile-push.devices.enabled', false));
    }

    /** The table itself (kept for callers that create only the table). */
    public static function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/'.self::MIGRATIONS[0];
    }

    /** @return list<string> every migration of the module, in run order (the table, then its later indexes) */
    public static function migrationPaths(): array
    {
        return array_map(static fn (string $file): string => dirname(__DIR__, 3).'/database/migrations/'.$file, self::MIGRATIONS);
    }
}
