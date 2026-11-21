<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;
use Padosoft\MobilePush\Laravel\Pulse\PulseModule;

/**
 * The Testbench application of the base TestCase already booted the package; this adds Livewire and Pulse (SQLite in
 * memory, Pulse's own migration) and boots the Pulse module with the flag on, as a host app would.
 */
final class PulseWorld
{
    /** @param  array<string, mixed>  $config */
    public static function build(Application $app, array $config = []): void
    {
        // mergeConfigFrom() is shallow: seed the whole `pulse` array so that no default recorder is registered.
        $pulse = require dirname(__DIR__, 4).'/vendor/laravel/pulse/config/pulse.php';
        $pulse['recorders'] = [];
        $pulse['ingest']['buffer'] = 100_000;
        $app['config']->set('pulse', $pulse);

        foreach (['mobile-push.pulse.enabled' => true, ...$config] as $key => $value) {
            $app['config']->set($key, $value);
        }

        $app->register(LivewireServiceProvider::class);
        $app->register(PulseServiceProvider::class);

        $migration = include dirname(__DIR__, 4).'/vendor/laravel/pulse/database/migrations/2023_06_07_000001_create_pulse_tables.php';
        $migration->up();

        PulseModule::boot($app);
    }
}
