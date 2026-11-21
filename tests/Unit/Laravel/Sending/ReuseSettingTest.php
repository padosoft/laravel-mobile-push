<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Transport\Curl\ConnectionReuse;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;
use Padosoft\MobilePush\Laravel\Testing\SwitchableTransport;

/*
 * Story: the application chooses connection reuse with `mobile-push.transport.reuse` (auto | on | off). The Transport
 * the container builds carries that choice; a value the package does not know stops the application before any
 * request is sent.
 */

function reuseBoundTransport(array $config): CurlTransport
{
    config()->set('mobile-push', $config);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(SendingSettings::class);
    $transport = app(Transport::class);
    if ($transport instanceof SwitchableTransport) {
        $transport = (new ReflectionProperty(SwitchableTransport::class, 'inner'))->getValue($transport);
    }
    expect($transport)->toBeInstanceOf(CurlTransport::class);

    return $transport;
}

it('binds the transport with the configured connection reuse, auto by default', function (array $config, ConnectionReuse $reuse, bool $reusesHere): void {
    $transport = reuseBoundTransport($config);

    expect($transport->reuse())->toBe($reuse)
        ->and($transport->reusesConnections())->toBe($reusesHere); // no Swoole in this process: auto reuses
})->with([
    'unset' => [[], ConnectionReuse::Auto, true],
    'auto' => [['transport' => ['reuse' => 'auto']], ConnectionReuse::Auto, true],
    'on' => [['transport' => ['reuse' => 'on']], ConnectionReuse::On, true],
    'off' => [['transport' => ['reuse' => 'off']], ConnectionReuse::Off, false],
    'off with max_pools' => [['transport' => ['reuse' => 'off', 'max_pools' => 8]], ConnectionReuse::Off, false],
]);

it('refuses an unknown transport.reuse value when the transport is built, naming the key', function (): void {
    expect(fn () => reuseBoundTransport(['transport' => ['reuse' => 'sometimes']]))
        ->toThrow(ConfigurationException::class, 'mobile-push.transport.reuse');
});
