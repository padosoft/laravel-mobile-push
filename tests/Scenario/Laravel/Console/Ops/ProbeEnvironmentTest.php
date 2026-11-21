<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
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

// The scenarios pin Carbon's clock to the fake clock: put the real one back when the application goes away.
beforeEach(function (): void {
    $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
});

/*
 * Staging tokens registered as production: the explicit environment probe is the ONLY thing that ever tries a token
 * on the other APNs environment. The oracle is the fake transport (which host, how many requests, which headers).
 */

/**
 * @return array{world: OpsWorld, devices: list<PushDevice>, tokens: list<string>}
 */
function probeStory(int $count = 2, bool $sandboxConnection = true, array $config = []): array
{
    $connections = ['ios' => W::apns()] + ($sandboxConnection ? ['ios-sandbox' => W::apns(keyId: 'KEY2222222', environment: 'sandbox')] : []);
    $world = OpsWorld::boot(['connections' => $connections + ['android' => W::fcm()]] + $config);
    $registrar = app(DeviceRegistrar::class);
    $devices = $tokens = [];
    for ($i = 1; $i <= $count; $i++) {
        $token = W::apnsToken('staging-'.$i);
        $tokens[] = $token;
        $device = $registrar->register($world->binding('ios'), ApnsDeviceToken::of($token), Customer::withId($i));
        // BadDeviceToken on production: the registration was quarantined (never changed environment).
        DB::table('mobile_push_devices')->where('id', $device->getKey())->update(['state' => DeviceState::Quarantined->value, 'state_reason' => 'destination_rejected']);
        $devices[] = $device;
    }
    $world->secret(...$tokens);

    return ['world' => $world, 'devices' => $devices, 'tokens' => $tokens];
}

const PROBE_ARGS = ['--quarantined' => true, '--allow-cross-environment' => true, '--force' => true];

it('probes each quarantined device ONCE on the other environment with a silent push and re-labels only the accepted one', function (): void {
    ['world' => $world, 'devices' => [$accepted, $rejected], 'tokens' => [$goodToken, $badToken]] = probeStory();
    $generation = $accepted->generation;
    $sandbox = $world->binding('ios-sandbox');
    $world->w->answer = static fn (Request $r): Response => SendKit::tokenOf($r) === $badToken ? SendKit::apnsReason(400, 'BadDeviceToken') : new Response(200, ['apns-id' => '11111111-2222-4333-8444-555555555555']);

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    $requests = $world->w->pushRequests();
    expect($requests)->toHaveCount(2)
        ->and($world->w->requestsTo('api.sandbox.push.apple.com'))->toHaveCount(2)
        ->and($world->w->requestsTo('api.push.apple.com'))->toHaveCount(0)
        ->and(array_map(static fn (Request $r): string => SendKit::tokenOf($r), $requests))->toEqualCanonicalizing([$goodToken, $badToken]);
    foreach ($requests as $request) {
        $body = json_decode($request->body, true);
        expect($request->headers['apns-push-type'])->toBe('background')
            ->and($request->headers['apns-priority'])->toBe('5')
            ->and($request->headers['apns-topic'])->toBe(W::BUNDLE)
            ->and($body['aps'])->toBe(['content-available' => 1])
            ->and($body)->not->toHaveKey('alert');
    }

    $accepted = OpsWorld::device($accepted->getKey());
    $rejected = OpsWorld::device($rejected->getKey());
    expect($accepted->state)->toBe(DeviceState::Active)
        ->and($accepted->environment)->toBe(Environment::Sandbox)
        ->and($accepted->binding_identity)->toBe($sandbox->identity())
        ->and($accepted->binding_name)->toBe('ios-sandbox')
        ->and($accepted->generation)->toBe($generation + 1)
        ->and($accepted->state_reason)->toBeNull()
        ->and($rejected->state)->toBe(DeviceState::Quarantined)
        ->and($rejected->environment)->toBe(Environment::Production)
        ->and($rejected->generation)->toBe($generation)
        ->and($exit)->toBe(0)
        ->and($output)->toContain('relabelled')->toContain('still_rejected');
    $world->assertClean();
});

it('prints a table with masked tokens only', function (): void {
    ['world' => $world, 'tokens' => [$token]] = probeStory(1);

    $json = $world->json('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($json['rows'][0]['token'])->toBe(TokenMasker::mask($token))
        ->and($json['rows'][0]['outcome'])->toBe('relabelled')
        ->and(json_encode($json))->not->toContain($token);
    $world->assertClean();
});

it('asks for an interactive confirmation, and a "no" sends nothing', function (): void {
    ['world' => $world] = probeStory();
    $question = 'Send one silent push to the other APNs environment for 2 quarantined device(s)? Rejected tokens count against Apple\'s error budget.';

    $this->artisan('mobile-push:devices:probe-environment', ['--quarantined' => true, '--allow-cross-environment' => true])
        ->expectsConfirmation($question, 'no')
        ->assertExitCode(1);
    expect($world->w->transport->sentCount())->toBe(0);

    $this->artisan('mobile-push:devices:probe-environment', ['--quarantined' => true, '--allow-cross-environment' => true])
        ->expectsConfirmation($question, 'yes')
        ->assertExitCode(0);
    expect($world->w->requestsTo('api.sandbox.push.apple.com'))->toHaveCount(2)
        ->and(PushDevice::query()->where('state', 'active')->count())->toBe(2);
});

it('refuses without --allow-cross-environment, without --force in a non-interactive run, and without a selection: zero requests', function (): void {
    ['world' => $world] = probeStory();

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', ['--quarantined' => true, '--force' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('--allow-cross-environment')->toContain('Nothing was sent');

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', ['--quarantined' => true, '--allow-cross-environment' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('--force');

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', ['--allow-cross-environment' => true, '--force' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('--quarantined');

    expect($world->w->transport->sentCount())->toBe(0)
        ->and(PushDevice::query()->where('state', 'quarantined')->count())->toBe(2);
});

it('refuses a device that is not quarantined and never touches active devices', function (): void {
    ['world' => $world, 'devices' => [$one]] = probeStory(1);
    DB::table('mobile_push_devices')->where('id', $one->getKey())->update(['state' => 'active']);

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', ['--device' => (string) $one->getKey(), '--allow-cross-environment' => true, '--force' => true]);

    expect($exit)->toBe(0)->and($output)->toContain('No quarantined APNs device token matches')
        ->and($world->w->transport->sentCount())->toBe(0);
});

it('without a connection of the same app on the other environment nothing is sent', function (): void {
    ['world' => $world] = probeStory(sandboxConnection: false);

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($exit)->toBe(1)->and($output)->toContain('no_connection')->toContain('No APNs connection for the app')
        ->and($world->w->transport->sentCount())->toBe(0)
        ->and(PushDevice::query()->where('state', 'quarantined')->count())->toBe(2);
});

it('a sandbox connection for ANOTHER bundle id does not count', function (): void {
    $world = OpsWorld::boot(['connections' => [
        'ios' => W::apns(),
        'other-app-sandbox' => W::apns(keyId: 'KEY3333333', bundle: 'com.example.other', environment: 'sandbox'),
    ]]);
    $device = app(DeviceRegistrar::class)->register($world->binding('ios'), ApnsDeviceToken::of(W::apnsToken('x')), Customer::withId(1));
    DB::table('mobile_push_devices')->where('id', $device->getKey())->update(['state' => 'quarantined']);

    [$exit] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($exit)->toBe(1)->and($world->w->transport->sentCount())->toBe(0);
});

it('in simulation mode it only lists what it would do', function (): void {
    ['world' => $world] = probeStory(config: ['mode' => 'simulate']);

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', ['--quarantined' => true, '--allow-cross-environment' => true]);

    // No confirmation was asked (nothing would be sent) and nothing was sent.
    expect($exit)->toBe(0)->and($output)->toContain('not_sent')->toContain('Simulation mode: would probe')
        ->and($world->w->transport->sentCount())->toBe(0)
        ->and(PushDevice::query()->where('state', 'quarantined')->count())->toBe(2);
});

it('outside production a live probe also needs live.allow and the device in live.only_to', function (): void {
    ['world' => $world] = probeStory();
    app()['env'] = 'staging';
    W::forgetSingletons();

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($exit)->toBe(1)->and($output)->toContain('blocked')->toContain('live sending outside production')
        ->and($world->w->transport->sentCount())->toBe(0);
});

it('stops the whole run on an authentication error: the key may be limited to one environment', function (): void {
    ['world' => $world, 'tokens' => $tokens] = probeStory(3);
    $world->w->answer = static fn (): Response => SendKit::apnsReason(403, 'InvalidProviderToken');

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($world->w->pushRequests())->toHaveCount(1)   // the first answer was the refusal: nobody else was contacted
        ->and($exit)->toBe(2)
        ->and($output)->toContain('authentication_error')->toContain('not_contacted')->toContain('limited to one environment')
        ->and(PushDevice::query()->where('state', 'quarantined')->count())->toBe(3)
        ->and(PushDevice::query()->where('environment', 'sandbox')->count())->toBe(0);
    $world->assertClean();
});

it('respects --limit: a small batch per run, the rest is reported', function (): void {
    ['world' => $world] = probeStory(3);

    $json = $world->json('mobile-push:devices:probe-environment', PROBE_ARGS + ['--limit' => 2]);

    expect($world->w->pushRequests())->toHaveCount(2)
        ->and($json['not_probed_over_limit'])->toBe(1)
        ->and(PushDevice::query()->where('state', 'quarantined')->count())->toBe(1);

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS + ['--limit' => 500]);
    expect($exit)->toBe(1)->and($output)->toContain('--limit cannot exceed');
});

it('leaves a device alone when the answer is anything but accepted or BadDeviceToken', function (): void {
    ['world' => $world, 'devices' => [$one]] = probeStory(1);
    $world->w->answer = static fn (): Response => SendKit::apnsReason(400, 'PayloadEmpty');

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($exit)->toBe(1)->and($output)->toContain('untouched')
        ->and(OpsWorld::device($one->getKey())->state)->toBe(DeviceState::Quarantined)
        ->and(OpsWorld::device($one->getKey())->environment)->toBe(Environment::Production);
});

it('does not re-label when the same token is already registered for the other environment', function (): void {
    ['world' => $world, 'devices' => [$one], 'tokens' => [$token]] = probeStory(1);
    app(DeviceRegistrar::class)->register($world->binding('ios-sandbox'), ApnsDeviceToken::of($token), Customer::withId(99));

    [$exit, $output] = $world->call('mobile-push:devices:probe-environment', PROBE_ARGS);

    expect($exit)->toBe(1)->and($output)->toContain('conflict')
        ->and(OpsWorld::device($one->getKey())->state)->toBe(DeviceState::Quarantined);
});
