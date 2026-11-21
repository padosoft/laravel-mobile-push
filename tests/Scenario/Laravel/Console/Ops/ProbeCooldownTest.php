<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\DeviceState;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/OpsWorld.php';

beforeEach(function (): void {
    $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
});

/*
 * The environment probe contacts Apple with tokens that were already rejected once: every rejected probe counts
 * against the error budget. A cron or an impatient operator must not be able to repeat it for the same device.
 */

/** @return array{world: OpsWorld, tokens: list<string>} quarantined devices that Apple keeps rejecting on sandbox too */
function cooldownStory(int $count, array $config = []): array
{
    $world = OpsWorld::boot(['connections' => ['ios' => W::apns(), 'ios-sandbox' => W::apns(keyId: 'KEY2222222', environment: 'sandbox'), 'android' => W::fcm()]] + $config);
    $tokens = [];
    for ($i = 1; $i <= $count; $i++) {
        $token = W::apnsToken('cooldown-'.$i);
        $tokens[] = $token;
        $device = app(DeviceRegistrar::class)->register($world->binding('ios'), ApnsDeviceToken::of($token), Customer::withId($i));
        DB::table('mobile_push_devices')->where('id', $device->getKey())->update(['state' => DeviceState::Quarantined->value, 'state_reason' => 'destination_rejected']);
    }
    $world->secret(...$tokens);
    $world->w->answer = static fn (Request $r): Response => SendKit::apnsReason(400, 'BadDeviceToken');

    return ['world' => $world, 'tokens' => $tokens];
}

const COOLDOWN_ARGS = ['--quarantined' => true, '--allow-cross-environment' => true, '--force' => true];

it('the cron runs the probe every hour: each quarantined device is contacted once in 24 hours', function (): void {
    ['world' => $world, 'tokens' => $tokens] = cooldownStory(3);

    for ($hour = 0; $hour < 24; $hour++) {
        $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);
        $world->advance(3600);
    }

    $perToken = array_count_values(array_map(static fn (Request $r): string => SendKit::tokenOf($r), $world->w->pushRequests()));
    expect($world->w->pushRequests())->toHaveCount(3)
        ->and($perToken)->toHaveCount(3)
        ->and(array_unique(array_values($perToken)))->toBe([1]);

    // The 25th hour: the cooldown (24 h) is over, the devices are still quarantined and are probed once more.
    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);
    expect($world->w->pushRequests())->toHaveCount(6);
    $world->assertClean();
});

it('says per device why it was not contacted, and --force does not bypass the cooldown', function (): void {
    ['world' => $world, 'tokens' => [$token]] = cooldownStory(1);
    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);
    $world->advance(3600);

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);

    expect($exit)->toBe(0)
        ->and($world->w->pushRequests())->toHaveCount(1)
        ->and($output)->toContain('cooling_down')->toContain('still_rejected')->toContain('--ignore-cooldown')
        ->and(PushDevice::query()->where('state', 'quarantined')->count())->toBe(1);

    $json = $world->json('mobile-push:devices:probe-environment', COOLDOWN_ARGS);
    expect($json['rows'][0]['outcome'])->toBe('cooling_down')
        ->and($json['cooling_down'])->toBe(1)
        ->and(json_encode($json))->not->toContain($token);
});

it('--ignore-cooldown always asks, even with --force, and refuses a non-interactive run', function (): void {
    ['world' => $world] = cooldownStory(1);
    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);
    $question = 'Probe devices even when they were probed less than the cooldown ago? Every rejected probe counts against Apple\'s error budget.';

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS + ['--ignore-cooldown' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('interactive confirmation')
        ->and($world->w->pushRequests())->toHaveCount(1);

    $this->artisan('mobile-push:devices:probe-environment', COOLDOWN_ARGS + ['--ignore-cooldown' => true])
        ->expectsConfirmation($question, 'no')
        ->assertExitCode(1);
    expect($world->w->pushRequests())->toHaveCount(1);

    $this->artisan('mobile-push:devices:probe-environment', COOLDOWN_ARGS + ['--ignore-cooldown' => true])
        ->expectsConfirmation($question, 'yes')
        ->assertExitCode(0);
    expect($world->w->pushRequests())->toHaveCount(2);
});

it('devices in cooldown do not take the places of the --limit: the next hour moves on to the next device', function (): void {
    ['world' => $world, 'tokens' => $tokens] = cooldownStory(2);

    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS + ['--limit' => 1]);
    $world->advance(3600);
    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS + ['--limit' => 1]);
    $world->advance(3600);
    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS + ['--limit' => 1]);

    $contacted = array_map(static fn (Request $r): string => SendKit::tokenOf($r), $world->w->pushRequests());
    expect($contacted)->toBe($tokens);   // each once, in order; the third run had nobody left
});

it('a cooldown of 0 hours switches the protection off', function (): void {
    ['world' => $world] = cooldownStory(1, ['devices.probe.cooldown_hours' => 0]);

    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);
    $world->call('mobile-push:devices:probe-environment', COOLDOWN_ARGS);

    expect($world->w->pushRequests())->toHaveCount(2);
});
