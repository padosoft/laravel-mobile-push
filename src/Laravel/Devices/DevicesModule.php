<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
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
        $migration = self::migrationPath();

        // A single file path is accepted by the migrator, so other modules' migrations in the same directory
        // are not loaded by accident.
        if ((bool) config('mobile-push.devices.enabled', false) && $app->bound('migrator')) {
            $app->make('migrator')->path($migration);
        }

        if ($app->runningInConsole()) {
            $target = $app->databasePath('migrations/'.date('Y_m_d_His').'_create_mobile_push_devices_table.php');

            // publishes() is protected: a throwaway provider registers the tag without touching the main provider.
            (new class($app) extends ServiceProvider
            {
                /** @param  array<string, string>  $paths */
                public function publishTagged(array $paths, string $tag): void
                {
                    $this->publishes($paths, $tag);
                }
            })->publishTagged([$migration => $target], self::PUBLISH_TAG);
        }
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/2026_11_20_000001_create_mobile_push_devices_table.php';
    }
}
