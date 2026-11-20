<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\ApnsConfig;
use Padosoft\MobilePush\Core\Apns\ApnsCredential;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Fcm\FcmConfig;
use Padosoft\MobilePush\Core\Fcm\FcmDriver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Transport\HttpVersion;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/** @return array{cert: string, key: string} a self-signed client certificate generated now */
function clientCertificate(string $cn = 'Apple Push Services: com.example.app'): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => $cn, 'organizationalUnitName' => SendKit::TEAM], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $cert);
    openssl_pkey_export($key, $pem);

    return ['cert' => (string) $cert, 'key' => (string) $pem];
}

it('requires the secondary APNs key to belong to the same team and to differ from the primary', function () {
    $kit = new SendKit;

    expect(fn () => new ApnsConfig(SendKit::apnsBinding(), $kit->apnsCredential('KEYA111111'), $kit->apnsCredential('KEYB222222', 'OTHERTEAM1')))
        ->toThrow(ConfigurationException::class, 'same team');
    expect(fn () => new ApnsConfig(SendKit::apnsBinding(), $kit->apnsCredential('KEYA111111'), $kit->apnsCredential('KEYA111111')))
        ->toThrow(ConfigurationException::class, 'different key');
});

it('refuses a certificate restricted to another environment, and a provider built for other ids', function () {
    $kit = new SendKit;
    $c = clientCertificate();

    expect(fn () => new ApnsConfig(SendKit::apnsBinding(), ApnsCredential::certificate(SendKit::TEAM, $c['cert'], $c['key'], environment: Environment::Sandbox)))
        ->toThrow(ConfigurationException::class, 'sandbox');
    expect(fn () => ApnsCredential::token($kit->apnsTokenProvider('KEYA111111'), SendKit::TEAM, 'KEYZ999999'))
        ->toThrow(ConfigurationException::class);
    expect(fn () => new ApnsConfig(new BindingRef('default', 'x', Provider::Fcm, 'p', Environment::Production), $kit->apnsCredential()))
        ->toThrow(ConfigurationException::class);
});

it('validates a client certificate against its private key and keeps the PEMs out of dumps', function () {
    $c = clientCertificate();
    $other = clientCertificate();

    expect(fn () => ApnsCredential::certificate(SendKit::TEAM, 'not a certificate', $c['key']))->toThrow(ConfigurationException::class, 'not a valid PEM');
    expect(fn () => ApnsCredential::certificate(SendKit::TEAM, $c['cert'], $other['key']))->toThrow(ConfigurationException::class, 'does not belong');

    $credential = ApnsCredential::certificate(SendKit::TEAM, $c['cert'], $c['key']);
    expect($credential->identity())->toBe('cert:'.strtolower((string) openssl_x509_fingerprint($c['cert'], 'sha256')))
        ->and(var_export($credential, true))->not->toContain('PRIVATE KEY')
        ->and(call_user_func('print_r', $credential, true))->not->toContain('PRIVATE KEY');
});

it('authenticates with the certificate on the connection and sends no authorization header with mTLS', function () {
    $c = clientCertificate();
    $driver = new ApnsDriver(new ApnsConfig(SendKit::apnsBinding(), ApnsCredential::certificate(SendKit::TEAM, $c['cert'], $c['key'])));
    $profile = $driver->connectionProfile(EndpointRole::ApnsSend, 0);
    $compiled = $driver->compiler()->compile(PushMessage::make('A', 'B'), SendKit::apnsToken(1),
        new CompileContext($driver->binding(), new DateTimeImmutable));

    expect($driver->tokenProvider(0))->toBeNull()
        ->and($profile->clientCertificatePem)->toBe($c['cert'])
        ->and($profile->clientKeyPem)->toBe($c['key'])
        ->and($driver->buildRequest('r1', $compiled, SendKit::apnsToken(1), null)->headers)->not->toHaveKey('authorization')
        ->and($profile->poolKey)->not->toBe((new SendKit)->apnsDriver()->connectionProfile(EndpointRole::ApnsSend, 0)->poolKey);
});

it('accepts endpoint overrides only outside production', function () {
    $kit = new SendKit;
    $override = new EndpointOverride([EndpointRole::ApnsSend->value => 'https://localhost:8443'], testCredentials: true);

    expect(fn () => new ApnsDriver(new ApnsConfig(SendKit::apnsBinding(), $kit->apnsCredential(), endpointOverride: $override)))
        ->toThrow(ConfigurationException::class);

    $driver = new ApnsDriver(new ApnsConfig(SendKit::apnsBinding(), $kit->apnsCredential(), endpointOverride: $override, applicationEnvironment: 'testing'));
    expect($driver->connectionProfile(EndpointRole::ApnsSend, 0)->poolKey)->not->toBe($kit->apnsDriver()->connectionProfile(EndpointRole::ApnsSend, 0)->poolKey);
});

it('validates the FCM config: production environment, distinct secondary, sane window', function () {
    $kit = new SendKit;
    $provider = $kit->fcmTokenProvider(SendKit::oauthAnswering());

    expect(fn () => new FcmConfig(new BindingRef('default', 'a', Provider::Fcm, 'p', Environment::Sandbox), $provider))->toThrow(ConfigurationException::class, 'production');
    expect(fn () => new FcmConfig(SendKit::fcmBinding(), $provider, $provider))->toThrow(ConfigurationException::class, 'different');
    expect(fn () => new FcmConfig(SendKit::fcmBinding(), $provider, window: 0))->toThrow(ConfigurationException::class);
    expect((new FcmDriver(new FcmConfig(SendKit::fcmBinding(), $provider, http2: false)))->connectionProfile(EndpointRole::FcmApi, 0)->httpVersion)
        ->toBe(HttpVersion::Http11);
});
