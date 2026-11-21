<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;

function cleanInstalled(): void
{
    @unlink(config_path('mobile-push.php'));
    foreach (glob(database_path('migrations/*mobile_push*')) ?: [] as $file) {
        @unlink($file);
    }
}

beforeEach(fn () => cleanInstalled());
afterEach(fn () => cleanInstalled());

/** @return list<string> */
function publishedMigrations(): array
{
    return array_map('basename', glob(database_path('migrations/*mobile_push*')) ?: []);
}

it('lets a junior install the light profile with the devices module from options alone', function () {
    [$code, $out] = C::run('mobile-push:install', ['--modules' => 'devices', '--profile' => 'light']);

    expect($code)->toBe(0)
        ->and(is_file(config_path('mobile-push.php')))->toBeTrue()
        ->and(publishedMigrations())->toHaveCount(1)
        ->and(publishedMigrations()[0])->toEndWith('create_mobile_push_devices_table.php')
        ->and($out)->toContain('MOBILE_PUSH_APNS_TEAM_ID=')
        ->toContain('MOBILE_PUSH_APNS_KEY_ID=')
        ->toContain('MOBILE_PUSH_FCM_CREDENTIALS_PATH=')
        ->toContain('MOBILE_PUSH_DEVICES_ENABLED=true')
        ->toContain("'push' => [")
        ->toContain("'push-bulk' => [")
        ->toContain("'balance' => 'auto'")
        ->toContain('php artisan queue:work')
        ->toContain('--queue=push-bulk')
        ->toContain('mobile-push:doctor');
});

it('publishes the ledger migrations as well with the durable profile', function () {
    [$code, $out] = C::run('mobile-push:install', ['--modules' => 'logs', '--profile' => 'durable']);

    $files = implode(' ', publishedMigrations());
    expect($code)->toBe(0)
        ->and($files)->toContain('create_mobile_push_ledger_effects_table')
        ->toContain('create_mobile_push_ledger_deliveries_table')
        ->toContain('create_mobile_push_logs_table')
        ->not->toContain('devices')
        ->and($out)->toContain('scheduler');
});

it('does not publish a second copy of migrations that are already there, and refuses nonsense before publishing anything', function () {
    C::run('mobile-push:install', ['--modules' => 'devices', '--profile' => 'light']);
    [, $out] = C::run('mobile-push:install', ['--modules' => 'devices', '--profile' => 'light']);

    expect(publishedMigrations())->toHaveCount(1)->and($out)->toContain('already');

    cleanInstalled();
    [$bad, $badOut] = C::run('mobile-push:install', ['--modules' => 'receipts', '--profile' => 'light']);
    [$unknown] = C::run('mobile-push:install', ['--modules' => 'bananas']);

    expect($bad)->toBe(2)->and($badOut)->toContain('durable profile')
        ->and($unknown)->toBe(2)
        ->and(publishedMigrations())->toBe([])
        ->and(is_file(config_path('mobile-push.php')))->toBeFalse();
});
