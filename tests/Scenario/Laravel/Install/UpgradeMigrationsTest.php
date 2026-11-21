<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\TestCase;

require_once __DIR__.'/../Devices/DeviceWorld.php';

/*
 * "The application was installed before the two index migrations existed, and it is upgraded while it is working."
 *
 * The durable profile with devices is installed with the first generation of migration files only (what an
 * application that published its migrations earlier has on disk). It then does real work on that schema: devices
 * are registered, pushes are queued, a worker claims a window and starts sending. The upgrade is what
 * docs/upgrading.md tells a person to do: run install again (it publishes only the files that are missing) and
 * migrate. Nothing that was in flight may be lost or sent twice, and the worker that held a lease across the upgrade
 * finishes its window.
 */

const UPGRADE_LATER_FILES = ['add_mobile_push_devices_audience_index.php', 'add_mobile_push_ledger_scaling_indexes.php'];

/** @return array{0: int, 1: string} */
function upgradeArtisan(string $command, array $arguments = []): array
{
    $code = Artisan::call($command, $arguments + ['--no-interaction' => true]);

    return [$code, Artisan::output()];
}

/** @return list<string> the names of the indexes of a table, sorted */
function upgradeIndexes(string $table): array
{
    $names = array_map(static fn (array $index): string => (string) $index['name'], Schema::getIndexes($table));
    sort($names);

    return $names;
}

/** @return array<string, int> deliveries per state */
function upgradeStates(): array
{
    $states = [];
    foreach (DB::table('mobile_push_deliveries')->selectRaw('state, count(*) as n')->groupBy('state')->get() as $row) {
        $states[(string) $row->state] = (int) $row->n;
    }
    ksort($states);

    return $states;
}

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir().'/mobile-push-upgrade-'.bin2hex(random_bytes(6));
    mkdir($this->tmp.'/database/migrations', 0777, true);
    mkdir($this->tmp.'/config', 0777, true);
    file_put_contents($this->tmp.'/config/mobile-push.php', "<?php\n\nreturn [];\n");

    $tmp = $this->tmp;
    TestCase::$environment = static function ($app) use ($tmp): void {
        $app->useDatabasePath($tmp.'/database');
        $app->useConfigPath($tmp.'/config');
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'mobile-push.migrations.autoload' => false,
            'mobile-push.delivery.profile' => 'durable',
            'mobile-push.devices.enabled' => true,
            'mobile-push.connections.ios' => W::apns(),
        ]);
    };
    $this->refreshApplication();
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

it('upgrades a working durable installation: the missing migrations are published and run, data and in-flight work survive', function (): void {
    // --- the installation as it was: every file but the two that did not exist yet
    [$code] = upgradeArtisan('mobile-push:install', ['--modules' => 'devices', '--profile' => 'durable']);
    expect($code)->toBe(0);
    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        foreach (UPGRADE_LATER_FILES as $later) {
            if (str_ends_with($file, $later)) {
                unlink($file);
            }
        }
    }
    $oldFiles = array_map('basename', glob(database_path('migrations/*.php')) ?: []);
    [$code, $out] = upgradeArtisan('migrate');
    expect($code)->toBe(0, $out);
    $oldMigrations = DB::table('migrations')->count();
    expect($oldMigrations)->toBe(count($oldFiles))
        ->and(upgradeIndexes('mobile_push_deliveries'))->toContain('mp_deliveries_due_idx')->not->toContain('mp_deliveries_claim_idx')
        ->and(upgradeIndexes('mobile_push_devices'))->not->toContain('mp_devices_audience_idx');

    // --- real work on the old schema
    $binding = MobilePush::binding('ios');
    $registrar = app(DeviceRegistrar::class);
    for ($i = 1; $i <= 30; $i++) {
        $registrar->register($binding, ApnsDeviceToken::of(sprintf('%064x', 0xD0000 + $i)), DeviceWorld::user($i));
    }
    Queue::fake();   // the relay jobs wait in the queue: the deliveries stay pending until a worker takes them
    $tokens = static fn (int $from): array => array_map(static fn (int $i): ApnsDeviceToken => ApnsDeviceToken::of(sprintf('%064x', $from + $i)), range(1, 40));
    foreach ([0xA0000, 0xB0000, 0xC0000] as $effect => $from) {
        MobilePush::to($tokens($from))->queue(PushMessage::make('Order '.$effect, 'Queued before the upgrade'));
    }
    $ledger = app(Ledger::class);
    $window = $ledger->claimDue('default', 'worker-before-the-upgrade', 10);
    $ledger->markAttemptsStarted(array_map(static fn ($claimed) => $claimed->lease, array_slice($window, 0, 5)));
    // What the worker records for a delivery the provider accepted (the ledger goes by the lease and the delivery id).
    $accepted = static fn ($claimed): array => [$claimed->lease, new SendResult(ApnsDeviceToken::of(str_repeat('ab', 32)), SendStatus::Accepted, Reason::of(ReasonCode::None), 1, deliveryId: $claimed->id()), null];
    $ledger->recordMany(array_map($accepted, array_slice($window, 0, 3)));

    $before = upgradeStates();
    $rowsBefore = DB::table('mobile_push_deliveries')->orderBy('id')->get(['id', 'effect_id', 'logical_key', 'state', 'version', 'lease_owner'])->map(static fn ($r): array => (array) $r)->all();
    expect(count($window))->toBe(10)
        ->and(array_sum($before))->toBe(120)
        ->and($before['accepted'] ?? 0)->toBe(3)
        ->and(DB::table('mobile_push_devices')->count())->toBe(30);

    // --- the upgrade, as documented: install again, then migrate
    [$code, $out] = upgradeArtisan('mobile-push:install', ['--modules' => 'devices', '--profile' => 'durable']);
    expect($code)->toBe(0, $out);
    $newFiles = array_map('basename', glob(database_path('migrations/*.php')) ?: []);
    $added = array_values(array_diff($newFiles, $oldFiles));
    expect($added)->toHaveCount(2)                                   // only what was missing; no second copy of the rest
        ->and(array_values(array_diff($oldFiles, $newFiles)))->toBe([]);
    foreach (UPGRADE_LATER_FILES as $later) {
        expect(array_filter($added, static fn (string $file): bool => str_ends_with($file, $later)))->toHaveCount(1);
    }
    [$code, $out] = upgradeArtisan('migrate');
    expect($code)->toBe(0, $out)->and($out)->not->toContain('already exists')
        ->and(DB::table('migrations')->count())->toBe($oldMigrations + 2);
    [$code, $out] = upgradeArtisan('migrate');
    expect($code)->toBe(0, $out)->and(DB::table('migrations')->count())->toBe($oldMigrations + 2);

    // --- after: the new indexes are there, the replaced ones are gone, and not one row changed
    expect(upgradeIndexes('mobile_push_deliveries'))
        ->toContain('mp_deliveries_claim_idx')->toContain('mp_deliveries_effect_claim_idx')->toContain('mp_deliveries_work_idx')
        ->not->toContain('mp_deliveries_due_idx')->not->toContain('mp_deliveries_effect_state_idx')
        ->and(upgradeIndexes('mobile_push_devices'))->toContain('mp_devices_audience_idx')
        ->and(upgradeStates())->toBe($before)
        ->and(DB::table('mobile_push_deliveries')->orderBy('id')->get(['id', 'effect_id', 'logical_key', 'state', 'version', 'lease_owner'])->map(static fn ($r): array => (array) $r)->all())->toEqual($rowsBefore)
        ->and(DB::table('mobile_push_devices')->count())->toBe(30);

    // --- the worker that held its leases across the upgrade finishes its window
    $rest = array_slice($window, 3);
    $started = $ledger->markAttemptsStarted(array_map(static fn ($claimed) => $claimed->lease, array_slice($rest, 2)));
    expect($started)->toHaveCount(5);
    $recordings = $ledger->recordMany(array_map($accepted, $rest));
    expect($recordings)->toHaveCount(7)
        ->and(array_values(array_unique(array_map(static fn (Recording $r): string => $r->name, $recordings))))->toBe(['Recorded']);

    // --- everything else is relayed by the upgraded application, once
    [$code, $out] = upgradeArtisan('mobile-push:relay');
    expect($code)->toBe(0, $out);
    $after = upgradeStates();
    expect(array_sum($after))->toBe(120)
        ->and($after['accepted'] ?? 0)->toBe(10)
        ->and(($after['accepted'] ?? 0) + ($after['simulated'] ?? 0))->toBe(120)       // nothing pending, nothing lost
        ->and(DB::table('mobile_push_deliveries')->distinct()->count('logical_key'))->toBe(120);   // and nothing twice

    // --- and the upgrade can be undone and done again (idempotent migrations, both directions)
    [$code, $out] = upgradeArtisan('migrate:rollback', ['--step' => 2]);
    expect($code)->toBe(0, $out)
        ->and(upgradeIndexes('mobile_push_deliveries'))->toContain('mp_deliveries_due_idx')->not->toContain('mp_deliveries_claim_idx')
        ->and(upgradeStates())->toBe($after);
    [$code, $out] = upgradeArtisan('migrate');
    expect($code)->toBe(0, $out)
        ->and(upgradeIndexes('mobile_push_deliveries'))->toContain('mp_deliveries_claim_idx')
        ->and(upgradeStates())->toBe($after);
});
