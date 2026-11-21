<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/OpsWorld.php';

/*
 * Canary: the clear tokens (and the bearer the fake Google endpoint hands out) used by the other scenarios are
 * pushed through every operational command, in both output formats, and must appear in none of the outputs.
 */

it('no command prints a clear token or a bearer, whatever it is asked to do', function (): void {
    $world = OpsWorld::boot(['connections' => ['ios' => W::apns(), 'ios-sandbox' => W::apns(keyId: 'KEY2222222', environment: 'sandbox'), 'android' => W::fcm()]], logs: true);
    $world->w->nextBearer = 'ya29.CANARY-BEARER-abcdefghijklmnopqrstuvwxyz0123456789';
    $apns = [W::apnsToken('canary-1'), W::apnsToken('canary-2')];
    $fcm = 'fake-registration-token-canary-'.str_repeat('Zq9', 20);
    $world->secret(...$apns, ...[$fcm, 'ya29.CANARY-BEARER-abcdefghijklmnopqrstuvwxyz0123456789']);

    $registrar = app(DeviceRegistrar::class);
    $devices = [];
    foreach ($apns as $i => $token) {
        $devices[] = $registrar->register($world->binding(), ApnsDeviceToken::of($token), Customer::withId(4242 + $i));
    }
    $world->queue([$devices[0]->destination(), $devices[1]->destination()], 'canary-order');
    DB::table('mobile_push_devices')->where('id', $devices[1]->getKey())->update(['state' => 'quarantined']);
    $world->w->answer = static fn (Request $r): Response => SendKit::tokenOf($r) === $apns[1] ? SendKit::apnsReason(400, 'BadDeviceToken') : new Response(200, ['apns-id' => '11111111-2222-4333-8444-555555555555']);

    foreach ([[], ['--json' => true]] as $format) {
        $world->call('mobile-push:relay', $format);
        $world->call('mobile-push:sweep', $format);
        $world->call('mobile-push:deliveries:prune', $format + ['--pretend' => true]);
        $world->call('mobile-push:devices:prune', $format + ['--pretend' => true]);
        $world->call('mobile-push:logs:prune', $format + ['--pretend' => true]);
        $world->call('mobile-push:health', $format);
        $world->call('mobile-push:credentials:check', $format + ['--no-alert' => true]);
        $world->call('mobile-push:devices:probe-environment', $format + ['--quarantined' => true, '--allow-cross-environment' => true, '--force' => true]);
        $world->call('mobile-push:fcm:topic-subscription', $format + ['action' => 'subscribe', 'topic' => 'news', '--token' => [$fcm]]);
        $world->call('mobile-push:fcm:topic-subscription', $format + ['action' => 'list', '--token' => [$fcm]]);
    }
    $world->call('mobile-push:export', ['owner' => Customer::class.':4242']);
    $world->call('mobile-push:forget', ['owner' => Customer::class.':4242', '--force' => true]);
    $world->call('mobile-push:forget', ['owner' => Customer::class.':4243', '--force' => true, '--json' => true]);

    expect(count($world->outputs))->toBeGreaterThan(20)
        ->and($world->w->transport->sentCount())->toBeGreaterThan(3);   // the commands really did talk to the (fake) providers
    $world->assertClean();
});
