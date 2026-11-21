<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Console\Support\XcrunRunner;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('exports a valid .apns file with the target bundle and says nothing was pushed', function () {
    $world = W::install(C::both());
    $path = sys_get_temp_dir().'/mp-sim-'.bin2hex(random_bytes(4)).'.apns';

    [$code, $out] = C::run('mobile-push:simulator', ['--bundle' => 'com.example.sim', '--export' => $path, '--title' => 'Hi', '--body' => 'Rendering', '--data' => ['deep=1']]);
    $file = json_decode((string) file_get_contents($path), true);
    @unlink($path);

    expect($code)->toBe(0)
        ->and($file['Simulator Target Bundle'])->toBe('com.example.sim')
        ->and($file['aps']['alert']['title'])->toBe('Hi')
        ->and($file['deep'])->toBe('1')
        ->and(array_key_first($file))->toBe('Simulator Target Bundle')
        ->and($out)->toContain('only exported')->toContain('RENDERS')->toContain('does not test APNs')
        ->and($world->transport->sentCount())->toBe(0);
});

it('takes the bundle of the only APNs connection, and refuses to guess among several', function () {
    W::install(C::both());
    $path = sys_get_temp_dir().'/mp-sim-'.bin2hex(random_bytes(4)).'.apns';

    [$code] = C::run('mobile-push:simulator', ['--export' => $path, '--title' => 'Hi']);
    expect($code)->toBe(0)->and(json_decode((string) file_get_contents($path), true)['Simulator Target Bundle'])->toBe(W::BUNDLE);
    @unlink($path);

    W::install(['connections' => ['a' => W::apns(bundle: 'com.a'), 'b' => W::apns(bundle: 'com.b')]]);
    [$code, $out] = C::run('mobile-push:simulator', ['--export' => $path, '--title' => 'Hi']);
    expect($code)->toBe(2)->and($out)->toContain('--bundle')->and(is_file($path))->toBeFalse();
});

it('needs --export when xcrun is not available', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:simulator', ['--bundle' => 'com.example.sim', '--title' => 'Hi']);

    expect($code)->toBe(2)->and($out)->toContain('--export');
});

it('pushes to the simulator through xcrun when it is available', function () {
    W::install(C::both());
    $calls = [];
    app()->instance(XcrunRunner::class, new class($calls) extends XcrunRunner
    {
        /** @param  list<array{string, string, string}>  $calls */
        public function __construct(public array &$calls) {}

        public function available(): bool
        {
            return true;
        }

        public function push(string $device, string $file): array
        {
            $this->calls[] = [$device, basename($file), (string) file_get_contents($file)];

            return [true, ''];
        }
    });

    [$code, $out] = C::run('mobile-push:simulator', ['--bundle' => 'com.example.sim', '--device' => 'ABCD-1234', '--title' => 'Hi']);

    expect($code)->toBe(0)->and($calls)->toHaveCount(1)->and($calls[0][0])->toBe('ABCD-1234')
        ->and($calls[0][2])->toContain('Simulator Target Bundle')
        ->and($out)->toContain('Pushed to the simulator');
});
