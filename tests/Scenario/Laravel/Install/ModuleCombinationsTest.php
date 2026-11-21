<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Console\Support\Doctor;
use Padosoft\MobilePush\Laravel\Console\Support\DoctorStatus;
use Padosoft\MobilePush\Laravel\Console\Support\InstallPlan;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\TestCase;

/*
 * "Whatever a team switches on, the package installs, migrates, sends and rolls back." Every combination of the five
 * optional modules with both delivery profiles (64 applications). Each one is BUILT on its configuration, the way a
 * real application boots with its .env, then goes through what a person does: install, migrate, migrate again,
 * doctor, health, a push, rollback. The tables each module owns are written here by hand, not read from the package.
 * Receipts with the light profile is the one combination install refuses (16 of the 64): that refusal is asserted.
 */

const COMBINATION_TABLES = [
    'devices' => ['mobile_push_devices'],
    'live-activities' => ['mobile_push_live_activities', 'mobile_push_live_activity_push_to_start_tokens', 'mobile_push_broadcast_channels'],
    'logs' => ['mobile_push_logs'],
    'campaigns' => ['mobile_push_campaigns'],
];

const COMBINATION_LEDGER = ['mobile_push_effects', 'mobile_push_deliveries'];

/** @return array<string, array{0: list<string>, 1: string}> */
function moduleCombinations(): array
{
    $all = InstallPlan::MODULES;
    $cases = [];
    for ($mask = 0; $mask < 2 ** count($all); $mask++) {
        $modules = array_values(array_filter($all, static fn (int $i): bool => ($mask >> $i & 1) === 1, ARRAY_FILTER_USE_KEY));
        foreach (InstallPlan::PROFILES as $profile) {
            $cases[$profile.' + '.($modules === [] ? 'no module' : implode(', ', $modules))] = [$modules, $profile];
        }
    }

    return $cases;
}

/** @return list<string> every table of the package that exists right now */
function packageTablesPresent(): array
{
    $all = [...array_merge(...array_values(COMBINATION_TABLES)), ...COMBINATION_LEDGER];
    sort($all);

    return array_values(array_filter($all, static fn (string $t): bool => Schema::hasTable($t)));
}

/** @return array{0: int, 1: string} */
function combinationArtisan(string $command, array $arguments = []): array
{
    $code = Artisan::call($command, $arguments + ['--no-interaction' => true]);

    return [$code, Artisan::output()];
}

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir().'/mobile-push-combo-'.bin2hex(random_bytes(6));
    mkdir($this->tmp.'/database/migrations', 0777, true);
    mkdir($this->tmp.'/config', 0777, true);
    // install keeps an existing config file instead of publishing it.
    file_put_contents($this->tmp.'/config/mobile-push.php', "<?php\n\nreturn [];\n");
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

it('installs, migrates, checks, sends and rolls back (or is refused before anything is published)', function (array $modules, string $profile): void {
    $tmp = $this->tmp;
    $on = static fn (string $module): bool => in_array($module, $modules, true);
    TestCase::$environment = static function ($app) use ($tmp, $on, $profile): void {
        $app->useDatabasePath($tmp.'/database');
        $app->useConfigPath($tmp.'/config');
        $app['config']->set([
            'mobile-push.migrations.autoload' => false,
            'mobile-push.delivery.profile' => $profile,
            'mobile-push.devices.enabled' => $on('devices'),
            'mobile-push.live_activities.enabled' => $on('live-activities'),
            'mobile-push.log.sinks' => $on('logs') ? ['database'] : [],
            'mobile-push.campaigns.enabled' => $on('campaigns'),
            'mobile-push.receipts.enabled' => $on('receipts'),
            'mobile-push.connections.ios' => W::apns(),
        ]);
    };
    $this->refreshApplication();

    // The one combination the package refuses: receipts are written to the delivery ledger, which the light profile
    // does not have. install says so and publishes nothing.
    if ($on('receipts') && $profile === 'light') {
        [$code, $out] = combinationArtisan('mobile-push:install', ['--modules' => implode(',', $modules), '--profile' => $profile]);
        expect($code)->toBe(2)
            ->and($out)->toContain('The receipts module writes to the delivery ledger and needs the durable profile.')
            ->and(glob($tmp.'/database/migrations/*.php') ?: [])->toBe([]);

        return;
    }

    $ledger = $profile === 'durable';
    $expected = $ledger ? COMBINATION_LEDGER : [];
    foreach (COMBINATION_TABLES as $module => $tables) {
        $expected = $on($module) ? [...$expected, ...$tables] : $expected;
    }
    sort($expected);

    // install, then migrate twice
    [$code, $out] = combinationArtisan('mobile-push:install', ['--modules' => implode(',', $modules), '--profile' => $profile]);
    expect($code)->toBe(0, $out);
    [$code, $out] = combinationArtisan('migrate');
    expect($code)->toBe(0, $out)
        ->and($out)->not->toContain('already exists')
        ->and(packageTablesPresent())->toBe($expected);
    if ($ledger) {
        expect(Schema::hasColumns('mobile_push_deliveries', ['message_id', 'receipt_marker']))->toBeTrue();
    }
    $ran = DB::table('migrations')->count();
    [$code, $out] = combinationArtisan('migrate');
    expect($code)->toBe(0, $out)->and(DB::table('migrations')->count())->toBe($ran);

    // the checks a person runs: no table the configuration needs is missing, and nothing asks for a table that is off
    $database = array_values(array_filter(app(Doctor::class)->run(), static fn ($check): bool => $check->section === 'Database'));
    foreach ($database as $check) {
        expect($check->status)->not->toBe(DoctorStatus::Failed, $check->label);
    }
    [, $doctor] = combinationArtisan('mobile-push:doctor');
    [, $health] = combinationArtisan('mobile-push:health');
    foreach ([$doctor, $health] as $output) {
        expect($output)->not->toContain('no such table')->not->toContain('SQLSTATE')->not->toContain('Base table');
    }

    // a push: simulated in this environment, whatever is switched on
    $results = iterator_to_array(MobilePush::toToken(Platform::Ios, W::apnsToken('combination'))->send(PushMessage::make('Order shipped'))->results(), false);
    expect(array_map(static fn ($r): string => $r->status->value, $results))->toBe(['simulated']);
    if ($profile === 'durable') {
        MobilePush::toToken(Platform::Ios, W::apnsToken('combination-queued'))->queue(PushMessage::make('Queued'));
        expect(DB::table('mobile_push_deliveries')->count())->toBe(1);
    }

    [$code, $out] = combinationArtisan('migrate:rollback');
    expect($code)->toBe(0, $out)->and(packageTablesPresent())->toBe([]);
})->with(moduleCombinations());
