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
