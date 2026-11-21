<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\DeviceState;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/OpsWorld.php';

/*
 * Retention: each prune command deletes only what is older than the retention; --pretend counts the same rows and
 * deletes none. Time is the fake clock, so "older" is exact.
 */

it('deliveries:prune keeps what is recent and --pretend reports the same counts without deleting', function (): void {
    $world = OpsWorld::boot();
    $token = static fn (string $s): ApnsDeviceToken => ApnsDeviceToken::of(W::apnsToken($s));

    $world->queue([$token('a'), $token('b'), $token('c')], 'old-order');
    $world->call('mobile-push:relay');
    $world->advance(40 * 86_400);
    $world->queue([$token('d')], 'recent-order');
    $world->call('mobile-push:relay');
    $world->advance(5 * 86_400);   // old: 45 days, recent: 5 days

    expect(DB::table('mobile_push_deliveries')->count())->toBe(4)
        ->and(DB::table('mobile_push_effects')->count())->toBe(2);

    $pretend = $world->json('mobile-push:deliveries:prune', ['--days' => 30, '--pretend' => true]);
    expect($pretend['would_delete'])->toBe(['deliveries' => 3, 'effects' => 1])
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(4)
        ->and(DB::table('mobile_push_effects')->count())->toBe(2);

    $real = $world->json('mobile-push:deliveries:prune', ['--days' => 30]);
    expect($real['deleted'])->toBe($pretend['would_delete'])
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(1)
        ->and(DB::table('mobile_push_effects')->count())->toBe(1);

    // The default comes from the retention key.
    config()->set('mobile-push.ledger.retention_days', 1);
    expect($world->json('mobile-push:deliveries:prune', ['--pretend' => true])['retention_days'])->toBe(1);
});

it('deliveries:prune can be limited to one scope', function (): void {
    $world = OpsWorld::boot();
    $world->queue([ApnsDeviceToken::of(W::apnsToken('a'))], 'k1', scope: 'default');
    $world->queue([ApnsDeviceToken::of(W::apnsToken('b'))], 'k2', scope: 'tenant2');
    $world->call('mobile-push:relay', ['--scope' => 'default']);
    $world->call('mobile-push:relay', ['--scope' => 'tenant2']);
    $world->advance(60 * 86_400);

    $world->json('mobile-push:deliveries:prune', ['--days' => 30, '--scope' => 'tenant2']);

    expect(DB::table('mobile_push_deliveries')->pluck('scope')->all())->toBe(['default']);
});

it('devices:prune deletes invalidated and long-unseen registrations and keeps the rest', function (): void {
    $world = OpsWorld::boot();
    $registrar = app(DeviceRegistrar::class);
    $binding = $world->binding();
    $register = static fn (string $s, int $user) => $registrar->register($binding, ApnsDeviceToken::of(W::apnsToken($s)), Customer::withId($user));
    $invalidatedOld = $register('inv-old', 1);
    $invalidatedNew = $register('inv-new', 2);
    $unseen = $register('unseen', 3);
    $fine = $register('fine', 4);

    $now = $world->w->clock->now();
    $at = static fn (int $days): string => DateTimeImmutable::createFromInterface($now)->modify("-{$days} days")->format('Y-m-d H:i:s.v');
    $update = static fn ($device, array $changes) => DB::table('mobile_push_devices')->where('id', $device->getKey())->update($changes);
    $update($invalidatedOld, ['state' => DeviceState::Invalidated->value, 'state_changed_at' => $at(40)]);
    $update($invalidatedNew, ['state' => DeviceState::Invalidated->value, 'state_changed_at' => $at(3)]);
    $update($unseen, ['last_seen_at' => $at(400)]);

    $pretend = $world->json('mobile-push:devices:prune', ['--pretend' => true]);
    expect($pretend['would_delete'])->toBe(['invalidated' => 1, 'inactive' => 1, 'total' => 2])
        ->and(DB::table('mobile_push_devices')->count())->toBe(4);

    $real = $world->json('mobile-push:devices:prune', ['--invalidated-days' => 30, '--inactive-days' => 365]);
    expect($real['deleted'])->toBe(['invalidated' => 1, 'inactive' => 1, 'total' => 2])
        ->and(DB::table('mobile_push_devices')->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([(int) $invalidatedNew->getKey(), (int) $fine->getKey()]);
});

it('logs:prune deletes only rows older than the retention', function (): void {
    $world = OpsWorld::boot(logs: true);
    $row = static fn (string $at): array => [
        'created_at' => $at, 'scope' => 'default', 'provider' => 'apns', 'binding_name' => 'ios', 'app_id' => W::BUNDLE,
        'environment' => 'production', 'operation' => 'send', 'destination_kind' => 'apns_device_token',
        'destination_index' => 'v1:'.str_repeat('a', 64), 'destination_masked' => 'a1b2c3…9f0e', 'status' => 'accepted', 'reason_code' => 'none',
    ];
    $now = $world->w->clock->now();
    $stamp = static fn (int $days): string => DateTimeImmutable::createFromInterface($now)->modify("-{$days} days")->format('Y-m-d H:i:s.v');
    DB::table('mobile_push_logs')->insert([$row($stamp(45)), $row($stamp(31)), $row($stamp(29)), $row($stamp(1))]);

    $pretend = $world->json('mobile-push:logs:prune', ['--days' => 30, '--pretend' => true]);
    expect($pretend['would_delete'])->toBe(['log_rows' => 2])
        ->and(DB::table('mobile_push_logs')->count())->toBe(4);

    $real = $world->json('mobile-push:logs:prune', ['--days' => 30]);
    expect($real['deleted'])->toBe(['log_rows' => 2])
        ->and(DB::table('mobile_push_logs')->count())->toBe(2);
});

it('each prune command says politely that its module is off and exits 0 (schedulable)', function (): void {
    $world = OpsWorld::boot(profile: 'light', config: ['devices.enabled' => false]);
    config()->set('mobile-push.devices.enabled', false);

    foreach ([['mobile-push:deliveries:prune', 'light delivery profile'], ['mobile-push:devices:prune', 'devices module is disabled'], ['mobile-push:logs:prune', 'database log sink is not enabled']] as [$command, $words]) {
        [$exit, $output] = $world->call($command);
        expect($exit)->toBe(0)->and($output)->toContain('Skipped')->toContain($words);
    }
});
