<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;
use Padosoft\MobilePush\Core\Policy\EndpointRole;

it('Nobody can point the package at an arbitrary host', function () {
    $override = new EndpointOverride(['apns_send' => 'https://attacker.test'], testCredentials: true);
    $noFlag = new EndpointOverride(['apns_send' => 'https://attacker.test']);

    // Refused in production, even with the test-credentials flag.
    expect(fn () => new EndpointCatalog('production', override: $override))->toThrow(ConfigurationException::class)
        // Refused without the flag, in any environment.
        ->and(fn () => new EndpointCatalog('local', override: $noFlag))->toThrow(ConfigurationException::class);

    // The only way to a custom host is explicit, non-production and flagged; and then it is the catalog's own origin.
    $test = new EndpointCatalog('local', override: $override);
    expect($test->apnsSendUrl(Environment::Sandbox, 'abc123'))->toBe('https://attacker.test/3/device/abc123');
});

it('A message cannot influence the URL: only typed, validated segments reach the path', function () {
    $c = new EndpointCatalog('production');
    $hostile = 'abc/../../v1/projects/other/messages:send?x=1#';

    expect(fn () => $c->apnsSendUrl(Environment::Production, $hostile))->toThrow(ConfigurationException::class)
        ->and(fn () => $c->fcmSendUrl('proj/../../x'))->toThrow(ConfigurationException::class)
        ->and(fn () => $c->apnsBroadcastPublishUrl(Environment::Production, 'com.x@evil.test'))->toThrow(ConfigurationException::class);

    $url = $c->apnsSendUrl(Environment::Production, 'abc123');
    expect(parse_url($url, PHP_URL_HOST))->toBe('api.push.apple.com');
    $c->assertOfficial($url, EndpointRole::ApnsSend);
    expect(fn () => $c->assertOfficial('https://evil.test/3/device/abc', EndpointRole::ApnsSend))->toThrow(ConfigurationException::class);
});
