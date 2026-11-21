<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Console\Support\Doctor;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('gives a healthy production setup a clean bill of health', function () {
    W::install(C::both());
    C::sharedCache();

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(0)
        ->and($out)->toContain('✓')
        ->toContain('HTTP/2 is available')
        ->toContain('Live sending')
        ->toContain('apns: com.example.shop')
        ->toContain('fcm: example-shop')
        ->toContain('Clock skew cannot be checked offline')
        ->not->toContain('✗');
});

it('warns about a certificate that is about to expire and says how many days are left', function () {
    $world = W::install([]);
    [$cert, $key] = C::certificate(10);
    $world->clock->set(gmdate('c', time() + 60));
    config()->set('mobile-push.connections', ['ios' => [
        'driver' => 'apns', 'auth' => 'certificate', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE, 'environment' => 'production',
        'certificate' => ['inline' => $cert], 'certificate_key' => ['inline' => $key],
    ]]);
    W::forgetSingletons();

    [$code, $out] = C::run('mobile-push:doctor', ['connection' => 'ios']);

    expect($code)->toBe(1)
        ->and($out)->toMatch('/expires in (9|10) day/')
        ->and($out)->toContain('Remedy:')
        ->and($out)->not->toContain('PRIVATE KEY')
        ->and($out)->not->toContain(C::canary($key));
});

it('fails with the remedy when the APNs key is on the wrong curve', function () {
    W::install(['connections' => ['ios' => W::apns(extra: ['key' => ['path' => null, 'base64' => base64_encode(W::ecPem('p384', 'secp384r1'))]])]]);

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(2)
        ->and($out)->toContain('✗')
        ->toContain('EC P-256')
        ->toContain('Remedy: Download the .p8 key again');
});

it('says in so many words that sends are simulated on a developer machine, and how to go live', function () {
    W::install(C::both(), environment: 'local');
    config()->set('mobile-push.mode', 'auto');
    W::forgetSingletons();

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(0)
        ->and($out)->toContain('SIMULATED')
        ->toContain('NO request is sent')
        ->toContain('MOBILE_PUSH_MODE=live');
});

it('warns when live sending is enabled outside production', function () {
    W::install(C::both(), environment: 'staging');

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(1)->and($out)->toContain('LIVE sending is enabled outside production');
});

it('says what Octane\'s Swoole curl coroutine hook means for sending: no reuse by default, a warning when reuse is forced on', function (mixed $flags, string $reuse, ?string $status) {
    config()->set('octane.swoole.options.hook_flags', $flags);
    config()->set('mobile-push.transport.reuse', $reuse);

    $lines = array_values(array_filter(app(Doctor::class)->run(), static fn ($check): bool => $check->section === 'Runtime'));

    expect(count($lines))->toBe($status === null ? 0 : 1);
    if ($status === 'info') {
        expect($lines[0]->status->value)->toBe('info')
            ->and($lines[0]->label)->toContain('curl coroutine hook')->toContain('do not reuse connections')->toContain('queue workers');
    }
    if ($status === 'warning') {
        expect($lines[0]->status->value)->toBe('warning')
            ->and($lines[0]->label)->toContain('crashes the worker')
            ->and($lines[0]->remedy)->toContain('"auto"')->toContain('queue workers');
    }
})->with([
    'no Octane config' => [null, 'auto', null],
    'every hook (SWOOLE_HOOK_ALL as Swoole 6 defines it), reuse auto' => [2143287295, 'auto', 'info'],
    'the native curl hook alone, reuse off' => [4096, 'off', 'info'],
    'the older curl hook alone, reuse auto' => [2048, 'auto', 'info'],
    'every hook, reuse forced on' => [2143287295, 'on', 'warning'],
    'every hook but the two curl ones, reuse on' => [2143287295 & ~(2048 | 4096), 'on', null],
]);

it('never reports queue timeouts as ok when they cannot be verified', function () {
    W::install(C::both());
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 300]);
    W::forgetSingletons();

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(1)
        ->and($out)->toContain('not verifiable')
        ->toContain('worker_timeout')
        ->and($out)->toMatch('/! not verifiable: .*worker_timeout/')
        ->and($out)->not->toMatch('/✓ job_timeout \(\d+ s\) < worker_timeout/');
});

it('reports a queue timeout violation with its remedy', function () {
    W::install(C::both());
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('mobile-push.queue.worker_timeout', 130);
    W::forgetSingletons();

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(2)->and($out)->toContain('violation')->toContain('Raise retry_after');
});

it('reports a missing table of an enabled module', function () {
    W::install(C::both());
    config()->set('mobile-push.devices.enabled', true);

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(2)->and($out)->toContain('mobile_push_devices')->toContain('vendor:publish --tag=mobile-push-migrations-devices');
});

it('refuses --live in simulate mode and sends nothing', function () {
    $world = W::install(C::both(), environment: 'local');
    config()->set('mobile-push.mode', 'simulate');
    W::forgetSingletons();

    [$code, $out] = C::run('mobile-push:doctor', ['--live' => true]);

    expect($code)->toBe(2)->and($out)->toContain('--live is refused')->toContain('MOBILE_PUSH_MODE=live')
        ->and($world->transport->sentCount())->toBe(0);
});

it('announces its live probes, then sends exactly one APNs push to an invalid token and one FCM validate_only call', function () {
    $world = W::install(C::both());
    C::sharedCache();

    [$code, $out] = C::run('mobile-push:doctor', ['--live' => true]);

    $apns = $world->requestsTo('api.push.apple.com');
    $fcm = $world->requestsTo('fcm.googleapis.com');

    expect($code)->toBe(0)
        ->and($world->pushRequests())->toHaveCount(2)
        ->and($apns)->toHaveCount(1)
        ->and($apns[0]->url)->toBe('https://api.push.apple.com/3/device/'.str_repeat('0', 64))
        ->and($fcm)->toHaveCount(1)
        ->and(json_decode($fcm[0]->body, true)['validate_only'] ?? null)->toBeTrue()
        ->and($out)->toContain('LIVE PROBE')->toContain('REAL requests')->toContain('APNs counts this request')
        ->and(strpos($out, 'LIVE PROBE'))->toBeLessThan(strpos($out, 'authentication works'))
        ->and($out)->toContain('authentication works')
        ->and($out)->not->toContain($world->nextBearer);
});

it('treats BadDeviceToken as proof that authentication works and an auth refusal as a failure', function () {
    $world = W::install(['connections' => ['ios' => W::apns()]]);
    C::sharedCache();
    $world->answer = static fn () => new Response(400, [], '{"reason":"BadDeviceToken"}');

    [$code, $out] = C::run('mobile-push:doctor', ['--live' => true]);
    expect($code)->toBe(0)->and($out)->toContain('BadDeviceToken')->toContain('authenticated the request');

    $world->answer = static fn () => new Response(403, [], '{"reason":"InvalidProviderToken"}');
    [$code, $out] = C::run('mobile-push:doctor', ['--live' => true]);
    expect($code)->toBe(2)->and($out)->toContain('authentication reasons');
});

it('prints a JSON document with --json', function () {
    W::install(C::both());
    C::sharedCache();

    [$code, $out] = C::run('mobile-push:doctor', ['--json' => true]);
    $data = json_decode($out, true);

    expect($code)->toBe(0)->and($data['exit_code'])->toBe(0)->and($data['checks'])->not->toBeEmpty();
});

it('on staging, --live probes a connection that allows live sends without asking for the probe token in live.only_to', function () {
    $world = W::install(['connections' => [
        'ios' => W::apns(extra: ['live' => ['allow' => true, 'only_to' => []]]),
        'android' => W::fcm(extra: ['live' => ['allow' => true, 'only_to' => []]]),
    ]], environment: 'staging');
    C::sharedCache();

    [, $out] = C::run('mobile-push:doctor', ['--live' => true]);

    expect($world->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and($world->requestsTo('fcm.googleapis.com'))->toHaveCount(1)
        ->and($out)->toContain('authentication works')
        ->and($out)->not->toContain('blocked before the network');
});

it('on staging, --live stays blocked for a connection that does not allow live sends, and says which key to set', function () {
    $world = W::install(['connections' => ['ios' => W::apns(extra: ['live' => ['allow' => false]])]], environment: 'staging');
    C::sharedCache();

    [, $out] = C::run('mobile-push:doctor', ['--live' => true]);

    expect($world->pushRequests())->toHaveCount(0)
        ->and($out)->toContain('blocked before the network')
        ->toContain('live.allow = true');
});

it('checks the campaigns table too, and warns when campaigns are enabled on the light profile', function () {
    W::install(C::both());
    config()->set('mobile-push.campaigns.enabled', true);

    [$code, $out] = C::run('mobile-push:doctor');

    expect($code)->toBe(2)
        ->and($out)->toContain('mobile_push_campaigns')->toContain('vendor:publish --tag=mobile-push-migrations-campaigns')
        ->and($out)->toContain('Campaigns are enabled but the delivery profile is "light"');
});
