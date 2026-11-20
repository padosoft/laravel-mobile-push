<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

beforeEach(function () {
    W::install();
    $this->factory = fn (): ConnectionFactory => app(ConnectionFactory::class);
});

it('builds the binding identity from the declared app and environment, never from the key', function () {
    $sandbox = ($this->factory)()->make('acme', 'ios', W::apns(environment: 'sandbox'));
    $fcm = ($this->factory)()->make('acme', 'android', W::fcm(extra: ['project_id' => null]));

    expect($sandbox->binding->toArray())->toMatchArray(['scope' => 'acme', 'name' => 'ios', 'provider' => 'apns', 'app_id' => W::BUNDLE, 'environment' => 'sandbox'])
        ->and($sandbox->binding->generation)->toBe(ConnectionFactory::generation('token:'.W::TEAM.':KEY1111111', ''))
        ->and($fcm->binding->appId)->toBe(W::PROJECT)
        ->and($fcm->binding->environment)->toBe(Environment::Production)
        ->and($fcm->binding->generation)->toBe(ConnectionFactory::generation(W::SA_EMAIL.'#sa-key-1', ''));
});

it('stores the live allowlist as keyed indexes of the scope, never as raw tokens', function () {
    $raw = W::apnsToken('qa');
    $connection = ($this->factory)()->make('acme', 'ios', W::apns(extra: ['live' => ['allow' => 'true', 'only_to' => $raw.',']]));

    expect($connection->live->allow)->toBeTrue()
        ->and($connection->live->onlyTo)->toBe([app(TokenIndex::class)->index(ApnsDeviceToken::of($raw), 'acme')])
        ->and(implode('', $connection->live->onlyTo))->not->toContain($raw);
});

dataset('invalid connections', [
    'no driver' => [['driver' => null], 'mobile-push.connections.ios.driver is required'],
    'unknown driver' => [['driver' => 'webpush'], 'mobile-push.connections.ios.driver must be one of: apns, fcm'],
    'no bundle' => [['bundle_id' => null], 'mobile-push.connections.ios.bundle_id is required'],
    'no team' => [['team_id' => ''], 'mobile-push.connections.ios.team_id is required'],
    'no key id' => [['key_id' => null], 'mobile-push.connections.ios.key_id is required'],
    'no key' => [['key' => ['path' => null, 'base64' => null]], 'mobile-push.connections.ios.key is required: point it to the .p8 key file'],
    'two key sources' => [['key' => ['path' => '/tmp/a.p8', 'inline' => 'x']], 'exactly one of: path, inline, base64, resolver'],
    'key not a source' => [['key' => 'AuthKey.p8'], 'mobile-push.connections.ios.key must be a secret source'],
    'resolver without reference' => [['key' => ['resolver' => 'App\\Vault']], 'needs a non-empty "reference"'],
    'bad environment' => [['environment' => 'development'], 'mobile-push.connections.ios.environment must be one of: production, sandbox'],
    'bad port' => [['port' => 8443], 'The APNs port must be 443 or 2197'],
    'bad broadcast host' => [['broadcast_host' => 'custom.example.com'], 'broadcast_host must be one of: standard, api-broadcast'],
    'bad only_to token' => [['live' => ['only_to' => ['not hex']]], 'live.only_to.0 is not a valid apns_device_token (the value is not shown)'],
    'timeouts inverted' => [['connect_timeout_ms' => 9000, 'timeout_ms' => 1000], 'request timeout not below the connect timeout'],
]);

it('refuses an invalid APNs connection before any request, naming the key', function (array $override, string $message) {
    $config = array_replace(W::apns(), $override);

    expect(fn () => ($this->factory)()->make('default', 'ios', $config))->toThrow(ConfigurationException::class, $message);
})->with('invalid connections');

dataset('invalid fcm connections', [
    'no credentials' => [['credentials' => null], 'mobile-push.connections.android.credentials is required'],
    'not json' => [['credentials' => ['inline' => 'not json']], 'not valid JSON'],
    'secondary of another project' => [['secondary' => ['credentials' => ['inline' => W::serviceAccountJson('another-project', 'push@another-project.iam.gserviceaccount.com', 'k2')]]], 'different projects'],
    'adc without project' => [['auth' => 'adc', 'credentials' => null], 'project_id is required with auth "adc"'],
    'bad http version' => [['http_version' => '3'], 'http_version must be one of: 2, 1.1'],
]);

it('refuses an invalid FCM connection before any request, naming the key', function (array $override, string $message) {
    $config = array_replace(W::fcm(), $override);

    expect(fn () => ($this->factory)()->make('default', 'android', $config))->toThrow(ConfigurationException::class, $message);
})->with('invalid fcm connections');
