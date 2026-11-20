<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\PoolKey;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Transport\HttpVersion;

/** @param  array<string, mixed>  $change */
function poolKeyWith(array $change = []): string
{
    $args = array_replace([
        'provider' => Provider::Apns,
        'role' => EndpointRole::ApnsSend,
        'host' => 'api.push.apple.com',
        'port' => 443,
        'credentialIdentity' => 'token:TEAM:KEY1',
        'environment' => Environment::Production,
        'httpVersion' => HttpVersion::Http2,
        'proxy' => null,
        'caBundlePath' => null,
        'credentialGeneration' => 0,
        'bindingGeneration' => '1',
    ], $change);

    return PoolKey::derive(...$args);
}

it('changes with every component of the isolation boundary', function (array $change) {
    expect(poolKeyWith($change))->not->toBe(poolKeyWith());
})->with([
    'provider' => [['provider' => Provider::Fcm]],
    'role' => [['role' => EndpointRole::ApnsBroadcastPublish]],
    'host' => [['host' => 'api.sandbox.push.apple.com']],
    'port' => [['port' => 2197]],
    'credential' => [['credentialIdentity' => 'token:TEAM:KEY2']],
    'certificate' => [['credentialIdentity' => 'cert:'.str_repeat('a', 64)]],
    'environment' => [['environment' => Environment::Sandbox]],
    'http version' => [['httpVersion' => HttpVersion::Http11]],
    'proxy' => [['proxy' => 'http://proxy.internal:3128']],
    'ca bundle' => [['caBundlePath' => '/etc/ssl/custom.pem']],
    'credential generation' => [['credentialGeneration' => 1]],
    'binding generation' => [['bindingGeneration' => '2']],
]);

it('is deterministic, case-insensitive on the host, and never shows the proxy or credential', function () {
    $key = poolKeyWith(['proxy' => 'http://user:s3cret@proxy.internal:3128']);

    expect(poolKeyWith())->toBe(poolKeyWith())
        ->and(poolKeyWith(['host' => 'API.PUSH.APPLE.COM']))->toBe(poolKeyWith())
        ->and($key)->toStartWith('apns:apns_send:')
        ->and($key)->not->toContain('s3cret')
        ->and($key)->not->toContain('KEY1');
});

it('cannot be forged by moving text between components', function () {
    expect(poolKeyWith(['host' => 'api.push.apple.com4', 'port' => 43]))->not->toBe(poolKeyWith(['host' => 'api.push.apple.com', 'port' => 443]));
});
