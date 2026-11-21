<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule;

/*
 * A junior installs the durable profile with devices and Live Activities and then runs `php artisan migrate`.
 * Everything happens in a temporary application path: nothing is written under vendor/.
 */

const INSTALL_TABLES = [
    'mobile_push_devices',
    'mobile_push_effects',
    'mobile_push_deliveries',
    'mobile_push_live_activities',
    'mobile_push_live_activity_push_to_start_tokens',
    'mobile_push_broadcast_channels',
];

/** Every migration file of the three modules (devices, ledger, Live Activities), counted from the modules themselves. */
function installMigrationFiles(): array
{
    return [...DevicesModule::migrationPaths(), ...LedgerModule::migrationPaths(), ...LiveActivitiesModule::migrationPaths()];
}

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir().'/mobile-push-install-'.bin2hex(random_bytes(6));
    mkdir($this->tmp.'/database/migrations', 0777, true);
    mkdir($this->tmp.'/config', 0777, true);
    // install keeps an existing config file instead of publishing it (the stale publish target is inside vendor/).
    file_put_contents($this->tmp.'/config/mobile-push.php', "<?php\n\nreturn [];\n");
    app()->useDatabasePath($this->tmp.'/database');
    app()->useConfigPath($this->tmp.'/config');
    config()->set('mobile-push.devices.enabled', true);
    config()->set('mobile-push.live_activities.enabled', true);
    config()->set('mobile-push.delivery.profile', 'durable');
});

afterEach(function (): void {
    $remove = static function (string $dir) use (&$remove): void {
        foreach (glob($dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? $remove($entry) : @unlink($entry);
        }
        @rmdir($dir);
    };
    $remove($this->tmp);
});

/** What the service provider does at boot, repeated after the test changed the configuration and the paths. */
function bootModulesAgain(): void
{
    foreach ([DevicesModule::class, LedgerModule::class, LiveActivitiesModule::class] as $module) {
        $module::boot(app());
    }
}

/** @return array{0: int, 1: string} */
function artisanOutput(string $command, array $arguments = []): array
{
    $code = Artisan::call($command, $arguments + ['--no-interaction' => true]);

    return [$code, Artisan::output()];
}

function installedTables(): array
{
    return array_values(array_filter(INSTALL_TABLES, static fn (string $t): bool => Schema::hasTable($t)));
}

function publishedFiles(): array
{
    return array_map('basename', glob(database_path('migrations/*.php')) ?: []);
}

it('a junior installs the durable profile with devices and Live Activities, then migrate creates every table once', function (): void {
    config()->set('mobile-push.migrations.autoload', false);
    bootModulesAgain();

    [$code, $out] = artisanOutput('mobile-push:install', ['--modules' => 'devices,live-activities', '--profile' => 'durable']);
    expect($code)->toBe(0)->and(publishedFiles())->toHaveCount(count(installMigrationFiles()));

    [$code, $out] = artisanOutput('migrate');
    expect($code)->toBe(0)->and($out)->not->toContain('already exists')
        ->and(installedTables())->toBe(INSTALL_TABLES)
        ->and(Schema::hasColumns('mobile_push_deliveries', ['message_id', 'receipt_marker']))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(count(installMigrationFiles()));

    [$code, $out] = artisanOutput('migrate');
    expect($code)->toBe(0)->and($out)->toContain('Nothing to migrate')
        ->and(DB::table('migrations')->count())->toBe(count(installMigrationFiles()));

    [$code] = artisanOutput('migrate:rollback');
    expect($code)->toBe(0)->and(installedTables())->toBe([]);
});

it('nothing is registered with the migrator by default: without publishing, migrate creates no package table', function (): void {
    bootModulesAgain();

    [$code] = artisanOutput('migrate');

    expect($code)->toBe(0)->and(installedTables())->toBe([]);
});

it('with migrations.autoload on the modules load their own files, install publishes nothing and says why', function (): void {
    config()->set('mobile-push.migrations.autoload', true);
    bootModulesAgain();

    [$code, $out] = artisanOutput('mobile-push:install', ['--modules' => 'devices,live-activities', '--profile' => 'durable']);
    expect($code)->toBe(0)->and(publishedFiles())->toBe([])
        ->and($out)->toContain('autoload is on')->toContain('nothing was published');

    [$code, $out] = artisanOutput('migrate');
    expect($code)->toBe(0)->and(installedTables())->toBe(INSTALL_TABLES)
        ->and(DB::table('migrations')->count())->toBe(count(installMigrationFiles()));

    [, $out] = artisanOutput('migrate');
    expect($out)->toContain('Nothing to migrate');

    artisanOutput('migrate:rollback');
    expect(installedTables())->toBe([]);
});

it('the trap: copies were published, then autoload is switched on: the published copy wins and the table is created once', function (): void {
    config()->set('mobile-push.migrations.autoload', false);
    bootModulesAgain();
    artisanOutput('mobile-push:install', ['--modules' => 'devices,live-activities', '--profile' => 'durable']);
    expect(publishedFiles())->toHaveCount(count(installMigrationFiles()));

    config()->set('mobile-push.migrations.autoload', true);
    bootModulesAgain();
    // The package files are not registered at all while a published copy of the same migration exists.
    $package = installMigrationFiles();
    expect(array_intersect(app('migrator')->paths(), $package))->toBe([]);

    [, $out] = artisanOutput('mobile-push:install', ['--modules' => 'devices', '--profile' => 'durable']);
    expect($out)->toContain('published copy');

    [$code, $out] = artisanOutput('migrate');
    expect($code)->toBe(0)->and($out)->not->toContain('already exists')
        ->and(installedTables())->toBe(INSTALL_TABLES)
        ->and(DB::table('migrations')->count())->toBe(count(installMigrationFiles()));
});

it('and even when both sets are registered by hand, the second run changes nothing and rollback still works (idempotent migrations)', function (): void {
    config()->set('mobile-push.migrations.autoload', false);
    bootModulesAgain();
    artisanOutput('mobile-push:install', ['--modules' => 'devices,live-activities', '--profile' => 'durable']);
    foreach (installMigrationFiles() as $file) {
        app('migrator')->path($file);
    }

    [$code, $out] = artisanOutput('migrate');
    expect($code)->toBe(0)->and($out)->not->toContain('already exists')
        ->and(installedTables())->toBe(INSTALL_TABLES)
        ->and(DB::table('migrations')->count())->toBe(count(installMigrationFiles()) * 2);

    [$code] = artisanOutput('migrate:rollback');
    expect($code)->toBe(0)->and(installedTables())->toBe([]);
});
