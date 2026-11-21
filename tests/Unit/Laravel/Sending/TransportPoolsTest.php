<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;
use Padosoft\MobilePush\Laravel\Testing\SwitchableTransport;

/** The real CurlTransport is bound (no FakeTransport): only its registry options are looked at, nothing is sent. */
function mpRealTransport(array $config): CurlTransport
{
    config()->set('mobile-push', $config);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(SendingSettings::class);
    $transport = app(Transport::class);
    if ($transport instanceof SwitchableTransport) {
        // The testing module decorates the bound transport; the CurlTransport built from the config is inside it.
        $transport = (new ReflectionProperty(SwitchableTransport::class, 'inner'))->getValue($transport);
    }
    expect($transport)->toBeInstanceOf(CurlTransport::class);

    return $transport;
}

it('caps the transport pool registry at 32 by default, and at transport.max_pools when set', function () {
    expect(mpRealTransport([])->registry()->maxPools())->toBe(32)
        ->and(mpRealTransport(['transport' => ['max_pools' => 200]])->registry()->maxPools())->toBe(200)
        ->and(mpRealTransport(['transport' => ['max_pools' => '7']])->registry()->maxPools())->toBe(7);
});

it('keeps the shared process registry while the cap is the default', function () {
    expect(mpRealTransport([])->registry())->toBe(CurlPoolRegistry::process())
        ->and(mpRealTransport(['transport' => ['max_pools' => 64]])->registry())->not->toBe(CurlPoolRegistry::process());
});

it('refuses a cap below 1, naming the key', function () {
    expect(fn () => mpRealTransport(['transport' => ['max_pools' => 0]]))
        ->toThrow(ConfigurationException::class, 'mobile-push.transport.max_pools');
});
