<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Queue\QueueModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/../Sending/SendingWorld.php';
require_once __DIR__.'/../Console/ConsoleWorld.php';

/*
 * A fresh Laravel application: the `database` queue connection with the skeleton's retry_after of 90, no horizon,
 * and the package configuration exactly as published. The doctor must not find a violation without any change.
 */
beforeEach(function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
});

it('the default timeout chain of a stock application has no violation, on the light and on the durable profile', function (string $profile): void {
    W::install(C::both() + ['delivery.profile' => $profile]);
    W::forgetSingletons();

    $chain = QueueModule::timeouts(app());

    expect($chain->violations())->toBe([])
        ->and($chain->isConsistent())->toBeTrue()
        ->and($chain->values['chunk_deadline'])->toBeGreaterThan($chain->values['transport_timeout'])
        ->and($chain->values['job_timeout'])->toBeGreaterThan($chain->values['chunk_deadline'])
        ->and($chain->values['retry_after'])->toBe(90.0)
        ->and($chain->values['job_timeout'])->toBeLessThan(90.0);
    if ($profile === 'durable') {
        expect($chain->values['lease_seconds'])->toBeGreaterThan($chain->values['chunk_deadline']);
    }

    // What `mobile-push:install` tells the operator to run is consistent with the same chain.
    [$code, $out] = C::run('mobile-push:doctor');
    expect($out)->not->toContain(': violation');
})->with(['light', 'durable']);

it('install prints a worker timeout and a retry_after floor that fit under the stock retry_after of 90', function (): void {
    W::install(C::both());
    W::forgetSingletons();
    // Nothing may be published under vendor/: a temporary application path, with the config file already in place.
    $tmp = sys_get_temp_dir().'/mobile-push-install-'.bin2hex(random_bytes(6));
    mkdir($tmp.'/config', 0777, true);
    file_put_contents($tmp.'/config/mobile-push.php', "<?php\n\nreturn [];\n");
    app()->useConfigPath($tmp.'/config');
    app()->useDatabasePath($tmp.'/database');

    [$code, $out] = C::run('mobile-push:install', ['--profile' => 'light']);
    @unlink($tmp.'/config/mobile-push.php');
    @rmdir($tmp.'/config');
    @rmdir($tmp);

    preg_match('/--timeout=(\d+)/', $out, $worker);
    preg_match('/Keep retry_after .* at (\d+) seconds or more/', $out, $floor);
    expect($worker)->not->toBe([])->and($floor)->not->toBe([])
        ->and((int) $worker[1])->toBeLessThan((int) $floor[1])
        ->and((int) $floor[1])->toBeLessThanOrEqual(90);

});
