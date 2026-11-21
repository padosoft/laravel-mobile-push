<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Policy\ApnsBroadcastHost;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;
use Padosoft\MobilePush\Core\Policy\EndpointRole;

it('builds the official URLs', function () {
    $c = new EndpointCatalog('production');
    $tok = 'abcdef0123';

    expect($c->apnsSendUrl(Environment::Production, $tok))->toBe('https://api.push.apple.com/3/device/abcdef0123')
        ->and($c->apnsSendUrl(Environment::Sandbox, $tok))->toBe('https://api.sandbox.push.apple.com/3/device/abcdef0123')
        ->and((new EndpointCatalog('production', apnsPort: 2197))->apnsSendUrl(Environment::Production, $tok))->toBe('https://api.push.apple.com:2197/3/device/abcdef0123')
        ->and($c->apnsChannelsUrl(Environment::Production, 'com.example.App'))->toBe('https://api-manage-broadcast.push.apple.com:2196/1/apps/com.example.App/channels')
        ->and($c->apnsAllChannelsUrl(Environment::Sandbox, 'com.example.App'))->toBe('https://api-manage-broadcast.sandbox.push.apple.com:2195/1/apps/com.example.App/all-channels')
        ->and($c->apnsBroadcastPublishUrl(Environment::Sandbox, 'com.example.App'))->toBe('https://api.sandbox.push.apple.com/4/broadcasts/apps/com.example.App')
        ->and((new EndpointCatalog('production', ApnsBroadcastHost::ApiBroadcast))->apnsBroadcastPublishUrl(Environment::Sandbox, 'com.example.App'))->toBe('https://api-broadcast.sandbox.push.apple.com/4/broadcasts/apps/com.example.App')
        ->and($c->fcmSendUrl('my-project-1'))->toBe('https://fcm.googleapis.com/v1/projects/my-project-1/messages:send')
        ->and($c->fcmTopicSubscriptionUrl('my-project-1', 'reg:1'))->toBe('https://fcm.googleapis.com/v1/projects/my-project-1/registrations/reg%3A1/topicSubscriptions')
        ->and($c->googleTokenUrl())->toBe('https://oauth2.googleapis.com/token');
});

it('rejects an unsupported APNs port', function () {
    expect(fn () => new EndpointCatalog('production', apnsPort: 8443))->toThrow(ConfigurationException::class);
});

it('rejects path injection in every typed input', function (string $bad) {
    $c = new EndpointCatalog('production');

    expect(fn () => $c->apnsSendUrl(Environment::Production, $bad))->toThrow(ConfigurationException::class)
        ->and(fn () => $c->apnsChannelsUrl(Environment::Production, $bad))->toThrow(ConfigurationException::class)
        ->and(fn () => $c->apnsBroadcastPublishUrl(Environment::Production, $bad))->toThrow(ConfigurationException::class)
        ->and(fn () => $c->fcmSendUrl($bad))->toThrow(ConfigurationException::class);
})->with(['../x', 'a/b', 'a?b=1', 'a#f', 'a b', '%2e%2e', '..', '', "a\nb", 'a@evil.com/', 'a\\b', 'a..b']);

it('verifies that URLs belong to a role', function () {
    $c = new EndpointCatalog('production');

    expect($c->isOfficial('https://api.push.apple.com/3/device/abc', EndpointRole::ApnsSend))->toBeTrue()
        ->and($c->isOfficial('https://api.push.apple.com:2197/3/device/abc', EndpointRole::ApnsSend))->toBeTrue()
        ->and($c->isOfficial('https://api.push.apple.com:8443/3/device/abc', EndpointRole::ApnsSend))->toBeFalse()
        ->and($c->isOfficial('http://api.push.apple.com/3/device/abc', EndpointRole::ApnsSend))->toBeFalse()
        ->and($c->isOfficial('https://api.push.apple.com.evil.test/3/device/abc', EndpointRole::ApnsSend))->toBeFalse()
        ->and($c->isOfficial('https://user@api.push.apple.com/3/device/abc', EndpointRole::ApnsSend))->toBeFalse()
        ->and($c->isOfficial('https://api.push.apple.com/3/device/../x', EndpointRole::ApnsSend))->toBeFalse()
        ->and($c->isOfficial('https://api.push.apple.com/3/device/abc', EndpointRole::FcmApi))->toBeFalse()
        ->and($c->isOfficial('https://fcm.googleapis.com/v1/projects/p/messages:send', EndpointRole::FcmApi))->toBeTrue()
        ->and($c->isOfficial('https://oauth2.googleapis.com/token', EndpointRole::GoogleTokenExchange))->toBeTrue()
        ->and($c->isOfficial('https://oauth2.googleapis.com/token/x', EndpointRole::GoogleTokenExchange))->toBeFalse();

    expect(fn () => $c->assertOfficial('https://evil.test/3/device/abc', EndpointRole::ApnsSend))->toThrow(ConfigurationException::class);
});

it('builds URLs on an override origin only outside production with test credentials', function () {
    $override = new EndpointOverride(['apns_send' => 'https://127.0.0.1:8443'], testCredentials: true);
    $c = new EndpointCatalog('testing', override: $override);

    expect($c->apnsSendUrl(Environment::Sandbox, 'abc'))->toBe('https://127.0.0.1:8443/3/device/abc');
    $c->assertOfficial('https://127.0.0.1:8443/3/device/abc', EndpointRole::ApnsSend);
    expect($c->isOfficial('https://api.push.apple.com/3/device/abc', EndpointRole::ApnsSend))->toBeFalse();
});

it('validates override origins', function (string $origin) {
    expect(fn () => new EndpointOverride(['apns_send' => $origin], true))->toThrow(ConfigurationException::class);
})->with(['http://x', 'https://x/path', 'https://u:p@x', 'https://x?q=1', 'ftp://x']);

it('builds the URL of one topic subscription and refuses a topic that would escape its path segment', function (string $topic, ?string $expected) {
    $c = new EndpointCatalog('production');
    $call = fn (): string => $c->fcmTopicSubscriptionItemUrl('my-project-1', 'reg:1', $topic);

    if ($expected === null) {
        expect($call)->toThrow(ConfigurationException::class);

        return;
    }
    expect($call())->toBe('https://fcm.googleapis.com/v1/projects/my-project-1/registrations/reg%3A1/topicSubscriptions/'.$expected);
})->with([
    'plain' => ['news', 'news'],
    'every allowed character' => ['a-b_c.d~e%41', 'a-b_c.d~e%2541'],
    'slash' => ['a/b', null],
    'query' => ['a?b', null],
    'dot dot' => ['..', null],
    'empty' => ['', null],
]);

it('builds the exact official URL for every role, environment and broadcast host choice', function (EndpointCatalog $catalog, Closure $build, string $url) {
    expect($build($catalog))->toBe($url);
})->with([
    'send, production, 443' => [new EndpointCatalog('production'), fn (EndpointCatalog $c) => $c->apnsSendUrl(Environment::Production, 'ab12'), 'https://api.push.apple.com/3/device/ab12'],
    'send, sandbox, 2197' => [new EndpointCatalog('production', apnsPort: 2197), fn (EndpointCatalog $c) => $c->apnsSendUrl(Environment::Sandbox, 'ab12'), 'https://api.sandbox.push.apple.com:2197/3/device/ab12'],
    'send, explicit 443' => [new EndpointCatalog('production', apnsPort: 443), fn (EndpointCatalog $c) => $c->apnsSendUrl(Environment::Sandbox, 'ab12'), 'https://api.sandbox.push.apple.com/3/device/ab12'],
    'channels, production, 2196' => [new EndpointCatalog('production'), fn (EndpointCatalog $c) => $c->apnsChannelsUrl(Environment::Production, 'com.example.App'), 'https://api-manage-broadcast.push.apple.com:2196/1/apps/com.example.App/channels'],
    'channels, sandbox, 2195' => [new EndpointCatalog('production'), fn (EndpointCatalog $c) => $c->apnsChannelsUrl(Environment::Sandbox, 'com.example.App'), 'https://api-manage-broadcast.sandbox.push.apple.com:2195/1/apps/com.example.App/channels'],
    'all channels, production, 2196' => [new EndpointCatalog('production'), fn (EndpointCatalog $c) => $c->apnsAllChannelsUrl(Environment::Production, 'com.example.App'), 'https://api-manage-broadcast.push.apple.com:2196/1/apps/com.example.App/all-channels'],
    'channels ignore the send port' => [new EndpointCatalog('production', apnsPort: 2197), fn (EndpointCatalog $c) => $c->apnsChannelsUrl(Environment::Sandbox, 'com.example.App'), 'https://api-manage-broadcast.sandbox.push.apple.com:2195/1/apps/com.example.App/channels'],
    'broadcast, standard host, production' => [new EndpointCatalog('production'), fn (EndpointCatalog $c) => $c->apnsBroadcastPublishUrl(Environment::Production, 'com.example.App'), 'https://api.push.apple.com/4/broadcasts/apps/com.example.App'],
    'broadcast, standard host ignores the send port' => [new EndpointCatalog('production', apnsPort: 2197), fn (EndpointCatalog $c) => $c->apnsBroadcastPublishUrl(Environment::Production, 'com.example.App'), 'https://api.push.apple.com/4/broadcasts/apps/com.example.App'],
    'broadcast, api-broadcast host, production' => [new EndpointCatalog('production', ApnsBroadcastHost::ApiBroadcast), fn (EndpointCatalog $c) => $c->apnsBroadcastPublishUrl(Environment::Production, 'com.example.App'), 'https://api-broadcast.push.apple.com/4/broadcasts/apps/com.example.App'],
    'broadcast, api-broadcast host, sandbox' => [new EndpointCatalog('production', ApnsBroadcastHost::ApiBroadcast), fn (EndpointCatalog $c) => $c->apnsBroadcastPublishUrl(Environment::Sandbox, 'com.example.App'), 'https://api-broadcast.sandbox.push.apple.com/4/broadcasts/apps/com.example.App'],
    'fcm send' => [new EndpointCatalog('production'), fn (EndpointCatalog $c) => $c->fcmSendUrl('p1'), 'https://fcm.googleapis.com/v1/projects/p1/messages:send'],
    'google token' => [new EndpointCatalog('production', apnsPort: 2197), fn (EndpointCatalog $c) => $c->googleTokenUrl(), 'https://oauth2.googleapis.com/token'],
]);

it('accepts exactly the APNs ports 443 and 2197', function (int $port, bool $ok) {
    $make = fn () => new EndpointCatalog('production', apnsPort: $port);

    $ok ? expect($make()->apnsSendUrl(Environment::Production, 'ab'))->toContain('api.push.apple.com') : expect($make)->toThrow(ConfigurationException::class);
})->with([[442, false], [443, true], [444, false], [2195, false], [2196, false], [2197, true], [2198, false]]);

it('recognises the official host, port and path of each role in both environments', function (EndpointCatalog $catalog, string $url, EndpointRole $role, bool $official) {
    expect($catalog->isOfficial($url, $role))->toBe($official);
})->with([
    'sandbox send host' => [new EndpointCatalog('production'), 'https://api.sandbox.push.apple.com/3/device/abc', EndpointRole::ApnsSend, true],
    'sandbox send host on 2197' => [new EndpointCatalog('production'), 'https://api.sandbox.push.apple.com:2197/3/device/abc', EndpointRole::ApnsSend, true],
    'explicit port 443' => [new EndpointCatalog('production'), 'https://api.push.apple.com:443/3/device/abc', EndpointRole::ApnsSend, true],
    'host in upper case' => [new EndpointCatalog('production'), 'https://API.Push.Apple.com/3/device/abc', EndpointRole::ApnsSend, true],
    'management, production on 2196' => [new EndpointCatalog('production'), 'https://api-manage-broadcast.push.apple.com:2196/1/apps/x/channels', EndpointRole::ApnsChannelManagement, true],
    'management, sandbox on 2195' => [new EndpointCatalog('production'), 'https://api-manage-broadcast.sandbox.push.apple.com:2195/1/apps/x/channels', EndpointRole::ApnsChannelManagement, true],
    'management, production host on the sandbox port' => [new EndpointCatalog('production'), 'https://api-manage-broadcast.push.apple.com:2195/1/apps/x/channels', EndpointRole::ApnsChannelManagement, false],
    'management, sandbox host on the production port' => [new EndpointCatalog('production'), 'https://api-manage-broadcast.sandbox.push.apple.com:2196/1/apps/x/channels', EndpointRole::ApnsChannelManagement, false],
    'management on 443' => [new EndpointCatalog('production'), 'https://api-manage-broadcast.push.apple.com/1/apps/x/channels', EndpointRole::ApnsChannelManagement, false],
    'broadcast, standard host' => [new EndpointCatalog('production'), 'https://api.sandbox.push.apple.com/4/broadcasts/apps/x', EndpointRole::ApnsBroadcastPublish, true],
    'broadcast, standard host on 2197' => [new EndpointCatalog('production'), 'https://api.push.apple.com:2197/4/broadcasts/apps/x', EndpointRole::ApnsBroadcastPublish, false],
    'broadcast, api-broadcast host when standard is chosen' => [new EndpointCatalog('production'), 'https://api-broadcast.push.apple.com/4/broadcasts/apps/x', EndpointRole::ApnsBroadcastPublish, false],
    'broadcast, api-broadcast production host' => [new EndpointCatalog('production', ApnsBroadcastHost::ApiBroadcast), 'https://api-broadcast.push.apple.com/4/broadcasts/apps/x', EndpointRole::ApnsBroadcastPublish, true],
    'broadcast, api-broadcast sandbox host' => [new EndpointCatalog('production', ApnsBroadcastHost::ApiBroadcast), 'https://api-broadcast.sandbox.push.apple.com/4/broadcasts/apps/x', EndpointRole::ApnsBroadcastPublish, true],
    'broadcast, standard host when api-broadcast is chosen' => [new EndpointCatalog('production', ApnsBroadcastHost::ApiBroadcast), 'https://api.push.apple.com/4/broadcasts/apps/x', EndpointRole::ApnsBroadcastPublish, false],
    'space in the path' => [new EndpointCatalog('production'), 'https://api.push.apple.com/3/device/ab c', EndpointRole::ApnsSend, false],
    'tab in the path' => [new EndpointCatalog('production'), "https://api.push.apple.com/3/device/ab\tc", EndpointRole::ApnsSend, false],
    'NUL byte' => [new EndpointCatalog('production'), "https://api.push.apple.com/3/device/ab\0c", EndpointRole::ApnsSend, false],
    'DEL byte' => [new EndpointCatalog('production'), "https://api.push.apple.com/3/device/ab\x7Fc", EndpointRole::ApnsSend, false],
    'backslash' => [new EndpointCatalog('production'), 'https://api.push.apple.com/3/device/ab\\c', EndpointRole::ApnsSend, false],
    'query' => [new EndpointCatalog('production'), 'https://api.push.apple.com/3/device/abc?x=1', EndpointRole::ApnsSend, false],
    'fragment' => [new EndpointCatalog('production'), 'https://api.push.apple.com/3/device/abc#x', EndpointRole::ApnsSend, false],
    'user and password' => [new EndpointCatalog('production'), 'https://u:p@api.push.apple.com/3/device/abc', EndpointRole::ApnsSend, false],
    'empty segment' => [new EndpointCatalog('production'), 'https://api.push.apple.com/3/device//abc', EndpointRole::ApnsSend, false],
    'dot segment' => [new EndpointCatalog('production'), 'https://api.push.apple.com/3/device/./abc', EndpointRole::ApnsSend, false],
    'no scheme' => [new EndpointCatalog('production'), '//api.push.apple.com/3/device/abc', EndpointRole::ApnsSend, false],
    'unparsable' => [new EndpointCatalog('production'), 'https://:443/3/device/abc', EndpointRole::ApnsSend, false],
    'fcm host on another port' => [new EndpointCatalog('production'), 'https://fcm.googleapis.com:8443/v1/projects/p/messages:send', EndpointRole::FcmApi, false],
]);

it('treats an override origin without a port as port 443, for that role only', function () {
    $c = new EndpointCatalog('testing', override: new EndpointOverride(['fcm_api' => 'https://MOCK.test'], testCredentials: true));

    expect($c->fcmSendUrl('p1'))->toBe('https://mock.test/v1/projects/p1/messages:send')
        ->and($c->isOfficial('https://mock.test/v1/projects/p1/messages:send', EndpointRole::FcmApi))->toBeTrue()
        ->and($c->isOfficial('https://mock.test:443/v1/projects/p1/messages:send', EndpointRole::FcmApi))->toBeTrue()
        ->and($c->isOfficial('https://mock.test:8443/v1/projects/p1/messages:send', EndpointRole::FcmApi))->toBeFalse()
        ->and($c->isOfficial('https://fcm.googleapis.com/v1/projects/p1/messages:send', EndpointRole::FcmApi))->toBeFalse()
        ->and($c->apnsSendUrl(Environment::Production, 'ab'))->toBe('https://api.push.apple.com/3/device/ab')
        ->and($c->isOfficial('https://api.push.apple.com/3/device/ab', EndpointRole::ApnsSend))->toBeTrue();
});

it('refuses an endpoint override for every spelling of a production environment', function (string $environment) {
    $override = new EndpointOverride([EndpointRole::FcmApi->value => 'https://127.0.0.1:8443'], testCredentials: true);

    expect(fn () => new EndpointCatalog($environment, override: $override))
        ->toThrow(ConfigurationException::class, 'never in production');
})->with(['production', 'Production', 'PRODUCTION', ' production', "production\n", 'prod', 'Prod']);

it('does not treat a percent-encoded dot segment as part of an official path', function () {
    $catalog = new EndpointCatalog('production');

    expect($catalog->isOfficial('https://fcm.googleapis.com/v1/projects/%2e%2e/%2e%2e/x', EndpointRole::FcmApi))->toBeFalse()
        ->and($catalog->isOfficial('https://fcm.googleapis.com/v1/projects/%2E%2E/x', EndpointRole::FcmApi))->toBeFalse()
        ->and($catalog->isOfficial('https://fcm.googleapis.com/v1/projects/demo/messages:send', EndpointRole::FcmApi))->toBeTrue();
});
