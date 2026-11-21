<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerEraser;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerRef;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\StoreStatus;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../../Console/Ops/OpsWorld.php';

/*
 * "Forget me" and "export my data" for one owner, inside one scope. The oracle is the database (which rows are left)
 * and the fake transport (what a worker still sends afterwards).
 */

/**
 * Alice (two phones), Bob (one phone) and Alice's namesake in another scope (one phone); an accepted delivery to
 * Alice's first phone, a pending one for the three phones of the default scope, one for the other scope, send-log rows.
 */
function forgetStory(): array
{
    $world = OpsWorld::boot(logs: true);
    $registrar = app(DeviceRegistrar::class);
    $tokens = ['alice1' => W::apnsToken('alice-1'), 'alice2' => W::apnsToken('alice-2'), 'bob' => W::apnsToken('bob'), 'other' => W::apnsToken('alice-t2')];
    $world->secret(...array_values($tokens));
    $alice = Customer::withId(4242);

    $devices = [
        'alice1' => $registrar->register($world->binding(), ApnsDeviceToken::of($tokens['alice1']), $alice),
        'alice2' => $registrar->register($world->binding(), ApnsDeviceToken::of($tokens['alice2']), $alice),
        'bob' => $registrar->register($world->binding(), ApnsDeviceToken::of($tokens['bob']), Customer::withId(7)),
        'other' => $registrar->register($world->binding(scope: 'tenant2'), ApnsDeviceToken::of($tokens['other']), $alice),
    ];

    // Accepted: sent and recorded.
    $world->queue([$devices['alice1']->destination()], 'receipt-1');
    $world->call('mobile-push:relay');
    // Pending: queued, not relayed. The message carries a marker-looking string on purpose (it must never be exported).
    $marker = 'MARKER-CANARY-mp_r-0123456789';
    $world->secret($marker);
    $message = PushMessage::make('Order shipped', 'Your order is on its way')->data(['mp_r' => $marker])->ttl(7200);
    $world->queue([$devices['alice1']->destination(), $devices['alice2']->destination(), $devices['bob']->destination()], 'order-1', $message);
    $world->queue([$devices['other']->destination()], 'order-t2', $message, scope: 'tenant2');

    $log = static fn (string $scope, string $id, string $index): array => [
        'created_at' => '2026-11-20 10:00:00.000', 'scope' => $scope, 'provider' => 'apns', 'binding_name' => 'ios', 'app_id' => W::BUNDLE,
        'environment' => 'production', 'operation' => 'send', 'destination_kind' => 'apns_device_token', 'destination_index' => $index,
        'destination_masked' => 'a1b2c3…9f0e', 'status' => 'accepted', 'reason_code' => 'none', 'origin_type' => Customer::class, 'origin_id' => $id,
    ];
    DB::table('mobile_push_logs')->insert([
        $log('default', '4242', 'v1:'.str_repeat('1', 64)),
        $log('default', '4242', 'v1:'.str_repeat('2', 64)),
        $log('default', '7', 'v1:'.str_repeat('3', 64)),
        $log('tenant2', '4242', 'v1:'.str_repeat('4', 64)),
    ]);

    return ['world' => $world, 'devices' => $devices, 'tokens' => $tokens, 'message' => $message, 'marker' => $marker];
}

/** @return list<string> the device tokens of the requests the transport got after the first $skip ones */
function tokensSentAfter(OpsWorld $world, int $skip): array
{
    return array_map(static fn ($r): string => SendKit::tokenOf($r), array_slice($world->w->transport->requests(), $skip));
}

it('forget me: nothing of the person is left in their scope, the pending push is cancelled and the relay never contacts the provider for them', function (): void {
    ['world' => $world, 'devices' => $devices, 'tokens' => $tokens, 'message' => $message] = forgetStory();
    expect($world->w->transport->sentCount())->toBe(1)
        ->and(OpsWorld::deliveryStates())->toBe(['accepted' => 1, 'pending' => 4]);
    $sentBefore = 1;

    [$exit, $output] = $world->call('mobile-push:forget', ['owner' => Customer::class.':4242', '--force' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('devices: 2 row(s) affected')
        ->and($output)->toContain('ledger: pending deliveries cancelled: 2 row(s) affected')
        ->and($output)->toContain('send log: 2 row(s) affected');

    // No row of the scope holds the person (their tokens, their owner id).
    expect(DB::table('mobile_push_devices')->where('scope', 'default')->where('owner_id', '4242')->count())->toBe(0)
        ->and(DB::table('mobile_push_devices')->whereIn('id', [$devices['alice1']->getKey(), $devices['alice2']->getKey()])->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->where('scope', 'default')->where('owner_id', '4242')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->where('scope', 'default')->whereNotNull('destination')->count())->toBe(0)
        ->and(DB::table('mobile_push_logs')->where('scope', 'default')->where('origin_id', '4242')->count())->toBe(0);
    // Her pending deliveries are cancelled tombstones, the accepted one keeps its non-personal outcome.
    expect(OpsWorld::deliveryStates())->toBe(['accepted' => 1, 'cancelled' => 2, 'pending' => 2]);

    // Others are untouched: Bob, and the same user id in another scope.
    expect(DB::table('mobile_push_devices')->where('scope', 'default')->where('owner_id', '7')->count())->toBe(1)
        ->and(DB::table('mobile_push_devices')->where('scope', 'tenant2')->where('owner_id', '4242')->count())->toBe(1)
        ->and(DB::table('mobile_push_logs')->where('origin_id', '7')->count())->toBe(1)
        ->and(DB::table('mobile_push_logs')->where('scope', 'tenant2')->where('origin_id', '4242')->count())->toBe(1);

    // The worker that still holds the queued job: zero requests for her phones, Bob's push goes out.
    $world->call('mobile-push:relay');
    expect(tokensSentAfter($world, $sentBefore))->toBe([$tokens['bob']]);

    // The other scope still delivers to its own namesake.
    $world->call('mobile-push:relay', ['--scope' => 'tenant2']);
    expect(tokensSentAfter($world, $sentBefore + 1))->toBe([$tokens['other']]);

    // Re-queuing the same effect (a retried job, an app that does not know) does not resurrect her rows.
    $rows = DB::table('mobile_push_deliveries')->where('scope', 'default')->count();
    $world->queue([$devices['alice1']->destination(), $devices['alice2']->destination(), $devices['bob']->destination()], 'order-1', $message);
    $world->call('mobile-push:relay');
    expect(DB::table('mobile_push_deliveries')->where('scope', 'default')->count())->toBe($rows)
        ->and(DB::table('mobile_push_deliveries')->where('scope', 'default')->where('owner_id', '4242')->count())->toBe(0)
        ->and(tokensSentAfter($world, $sentBefore + 2))->toBe([]);
    $world->assertClean();
});

it('forget me states what it cannot reach, and that it does not make the app compliant', function (): void {
    ['world' => $world] = forgetStory();

    [, $output] = $world->call('mobile-push:forget', ['owner' => Customer::class.':4242', '--force' => true]);

    expect($output)->toContain('Not reached by this command')
        ->toContain('Database backups')
        ->toContain('OpenTelemetry')
        ->toContain('log files')
        ->toContain('Apple APNs, Google FCM')
        ->toContain('does not make the application compliant');
    $json = $world->json('mobile-push:forget', ['owner' => Customer::class.':4242', '--force' => true]);
    expect($json['not_reached'])->toHaveCount(5)->and($json['succeeded'])->toBeTrue();
    expect(json_encode($json))->not->toContain('4242');   // the owner id is never echoed
});

it('forget me is strictly scoped: --scope erases the other scope only', function (): void {
    ['world' => $world] = forgetStory();

    $world->call('mobile-push:forget', ['owner' => Customer::class.':4242', '--scope' => 'tenant2', '--force' => true]);

    expect(DB::table('mobile_push_devices')->where('scope', 'tenant2')->count())->toBe(0)
        ->and(DB::table('mobile_push_devices')->where('scope', 'default')->where('owner_id', '4242')->count())->toBe(2)
        ->and(DB::table('mobile_push_logs')->where('scope', 'default')->where('origin_id', '4242')->count())->toBe(2);
});

it('the host app can call the eraser directly, and a store that is not installed is reported as not present', function (): void {
    ['world' => $world] = forgetStory();

    $report = app(OwnerEraser::class)->forget(new OwnerRef(Customer::class, '4242'), 'default');

    $byStore = array_column(array_map(static fn ($s): array => $s->toArray(), $report->stores), null, 'store');
    expect($report->succeeded())->toBeTrue()
        ->and($byStore['devices']['affected'])->toBe(2)
        ->and($byStore['live activities']['status'])->toBe(StoreStatus::NotPresent->value)
        ->and($byStore['ledger: deliveries erased']['affected'])->toBe(3);
});

it('forget me asks for confirmation, refuses without it in a non-interactive run and rejects a malformed owner', function (): void {
    ['world' => $world] = forgetStory();

    [$exit, $output] = $world->call('mobile-push:forget', ['owner' => Customer::class.':4242']);
    expect($exit)->toBe(1)->and($output)->toContain('--force');

    [$exit, $output] = $world->call('mobile-push:forget', ['owner' => 'not-an-owner', '--force' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('Type:id');

    $this->artisan('mobile-push:forget', ['owner' => Customer::class.':4242'])
        ->expectsConfirmation('Erase everything this package holds about an owner of type '.Customer::class.' in scope "default"? This cannot be undone.', 'no')
        ->assertExitCode(1);
    expect(DB::table('mobile_push_devices')->where('owner_id', '4242')->where('scope', 'default')->count())->toBe(2);
});

it('export my data: devices and deliveries with masked tokens only, never a clear token or a marker', function (): void {
    ['world' => $world, 'tokens' => $tokens, 'marker' => $marker] = forgetStory();

    [$exit, $output] = $world->call('mobile-push:export', ['owner' => Customer::class.':4242']);
    $export = json_decode($output, true);

    expect($exit)->toBe(0)->and($export)->toBeArray()
        ->and($export['scope'])->toBe('default')
        ->and($export['devices']['rows'])->toHaveCount(2)
        ->and(array_column($export['devices']['rows'], 'token'))->toEqualCanonicalizing([TokenMasker::mask($tokens['alice1']), TokenMasker::mask($tokens['alice2'])])
        ->and($export['deliveries']['rows'])->toHaveCount(3)
        ->and(array_count_values(array_column($export['deliveries']['rows'], 'state')))->toEqual(['accepted' => 1, 'pending' => 2])
        ->and($export['send_log']['rows'])->toHaveCount(2)
        ->and($export['live_activities']['rows'])->toBe([])
        ->and($export['notes'][0])->toContain('masked');

    foreach ([...array_values($tokens), $marker, 'bob'] as $forbidden) {
        expect($output)->not->toContain($forbidden);
    }
    // Bob's phone and the other scope are not in it.
    expect($output)->not->toContain(TokenMasker::mask($tokens['bob']))->not->toContain(TokenMasker::mask($tokens['other']));
    $world->assertClean();
});

it('export writes a private file, never overwrites one, and reads only the scope asked for', function (): void {
    ['world' => $world, 'tokens' => $tokens] = forgetStory();
    $path = sys_get_temp_dir().'/mobile-push-export-'.bin2hex(random_bytes(6)).'.json';

    try {
        [$exit, $output] = $world->call('mobile-push:export', ['owner' => Customer::class.':4242', '--scope' => 'tenant2', '--output' => $path]);
        $written = json_decode((string) file_get_contents($path), true);

        expect($exit)->toBe(0)
            ->and($output)->toContain('Wrote')->not->toContain(TokenMasker::mask($tokens['other']))
            ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600')
            ->and($written['scope'])->toBe('tenant2')
            ->and(array_column($written['devices']['rows'], 'token'))->toBe([TokenMasker::mask($tokens['other'])])
            ->and(file_get_contents($path))->not->toContain($tokens['other']);

        [$again] = $world->call('mobile-push:export', ['owner' => Customer::class.':4242', '--output' => $path]);
        expect($again)->toBe(1)->and(json_decode((string) file_get_contents($path), true)['scope'])->toBe('tenant2');
    } finally {
        @unlink($path);
    }
});

it('light profile: a push already queued for the forgotten person finds no eligible device and never reaches the provider', function (): void {
    $world = OpsWorld::boot(profile: 'light');
    $token = W::apnsToken('alice-light');
    $world->secret($token);
    $device = app(DeviceRegistrar::class)->register($world->binding(), ApnsDeviceToken::of($token), Customer::withId(4242));
    $world->queue([$device->destination()], 'order-light');
    $job = Queue::pushed(SendPushJob::class)->first();
    expect($job)->not->toBeNull();

    [$exit, $output] = $world->call('mobile-push:forget', ['owner' => Customer::class.':4242', '--force' => true]);
    app()->call([$job, 'handle']);   // the worker finally picks the job up

    expect($exit)->toBe(0)
        ->and($output)->toContain('not present')   // there is no ledger on the light profile, and it says so
        ->and($world->w->transport->sentCount())->toBe(0)
        ->and(DB::table('mobile_push_devices')->count())->toBe(0);
});
