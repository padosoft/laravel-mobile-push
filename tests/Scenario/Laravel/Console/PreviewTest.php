<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('shows exactly what will go on the wire for APNs, with a placeholder destination and no credential', function () {
    $world = W::install(C::both());

    [$code, $out] = C::run('mobile-push:preview', ['--platform' => 'ios', '--title' => 'Order shipped', '--body' => 'Arrives Friday', '--data' => ['order=42'], '--badge' => '3', '--sound' => 'default']);

    expect($code)->toBe(0)
        ->and($out)->toContain('apns-push-type: alert')
        ->toContain('"title": "Order shipped"')
        ->toContain('"badge": 3')
        ->toContain('"order": "42"')
        ->toContain('placeholder')
        ->toContain('validated remotely')
        ->toMatch('/Size \d+ of 4096 bytes/')
        ->and(strtolower($out))->not->toContain('authorization')->not->toContain('bearer')
        ->and($world->transport->sentCount())->toBe(0);
});

it('lists what needs app code and what the platform cannot do', function () {
    W::install(C::both());

    [, $out] = C::run('mobile-push:preview', ['--platform' => 'android', '--title' => 'Hi', '--body' => 'There', '--type' => 'alert']);
    expect($out)->toContain('FCM')->toContain('"notification"');

    [$code] = C::run('mobile-push:preview', ['--platform' => 'ios', '--title' => 'x', '--type' => 'bogus']);
    expect($code)->toBe(2);
});

it('compiles for a named connection without printing any secret', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:preview', ['--connection' => 'ios', '--title' => 'Hi', '--body' => 'There']);

    expect($code)->toBe(0)
        ->and($out)->toContain('connection "ios"')->toContain('apns-topic: '.W::BUNDLE)
        ->and($out)->not->toContain('PRIVATE KEY')->not->toContain(C::canary(W::ecPem('KEY1111111')));
});

it('shows the byte count and the error for a message over the limit', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:preview', ['--platform' => 'ios', '--title' => 'Hi', '--body' => str_repeat('x', 6000)]);

    expect($code)->toBe(2)->and($out)->toMatch('/payload is \d+ bytes/')->toContain('4096');
});

it('needs a platform or a connection', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:preview', ['--title' => 'Hi']);

    expect($code)->toBe(2)->and($out)->toContain('never guessed');
});

it('prints JSON with the payload as an object', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:preview', ['--platform' => 'ios', '--title' => 'Hi', '--json' => true]);
    $data = json_decode($out, true);

    expect($code)->toBe(0)->and($data['payload']['aps']['alert']['title'])->toBe('Hi')->and($data['limit_bytes'])->toBe(4096);
});
