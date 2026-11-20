<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Credentials\ApnsCertificateInspector;
use Padosoft\MobilePush\Core\Credentials\ApnsKeyInspector;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\CredentialIdentity;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInspector;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountCredentials;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Padosoft\MobilePush\Tests\Unit\Core\Credentials\CertificateFactory;

it('the certificate expires next week: warning with the days left and the remedy; a day after expiry it is an error', function () {
    $made = CertificateFactory::make('Apple Production IOS Push Services: com.example.app');
    $info = (new ApnsCertificateInspector)->fromPem($made['cert'], $made['key']);
    $clock = new FakeClock($info->notAfter->modify('-7 days'));
    $check = new CredentialCheck($clock);

    $result = $check->certificate($info);
    $warning = $result->find('apns.certificate.expiring');
    expect($result->worst())->toBe(Severity::Warning)
        ->and($warning)->not->toBeNull()
        ->and($warning->message)->toContain('7 day')->toContain('Renew')
        ->and($result->passes())->toBeTrue();

    $clock->set($info->notAfter->modify('+1 day'));
    $expired = $check->certificate($info);
    expect($expired->worst())->toBe(Severity::Error)
        ->and($expired->codes(Severity::Error))->toBe(['apns.certificate.expired']);

    $clock->set($info->notAfter);
    expect($check->certificate($info)->codes(Severity::Error))->toBe(['apns.certificate.expired']);

    $clock->set($info->notAfter->modify('-31 days'));
    expect($check->certificate($info)->worst())->toBe(Severity::Ok);
    $clock->set($info->notAfter->modify('-30 days'));
    expect($check->certificate($info)->worst())->toBe(Severity::Warning);

    $clock->set($info->notBefore->modify('-1 day'));
    expect($check->certificate($info)->codes(Severity::Error))->toBe(['apns.certificate.not_yet_valid']);
});

it('someone uploaded the key of another app: an error that leaks no secret', function () {
    $mine = CertificateFactory::make();
    $theirs = CertificateFactory::make('Apple Push Services: com.other.app');
    $info = (new ApnsCertificateInspector)->fromPem($mine['cert'], $theirs['key']);

    $result = (new CredentialCheck(new FakeClock($info->notAfter->modify('-200 days'))))->certificate($info);

    expect($result->codes(Severity::Error))->toBe(['apns.certificate.key_mismatch']);
    $dump = var_export($result, true).print_r($result, true).var_export($info, true);
    $body = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $theirs['key']);
    expect($dump)->not->toContain('PRIVATE KEY')->not->toContain(substr($body, 60, 40))
        ->and(implode(' ', array_map(static fn ($f) => $f->message, $result->findings)))->not->toContain('BEGIN');
});

it('secondary key from another team: an error before any send', function () {
    $check = new CredentialCheck(new FakeClock);

    $result = $check->sameIdentity(
        CredentialIdentity::apnsKey('TEAMAAAAAA', Environment::Production),
        CredentialIdentity::apnsKey('TEAMBBBBBB', Environment::Production),
    );
    expect($result->codes(Severity::Error))->toBe(['credentials.identity.owner_mismatch'])
        ->and($result->find('credentials.identity.owner_mismatch')?->message)->toContain('never fails over');

    expect($check->sameIdentity(CredentialIdentity::apnsKey('T1'), CredentialIdentity::apnsKey('T1'))->passes())->toBeTrue();
    expect($check->sameIdentity(CredentialIdentity::apnsKey('T1', Environment::Sandbox), CredentialIdentity::apnsKey('T1', Environment::Production))->codes(Severity::Error))
        ->toBe(['credentials.identity.environment_mismatch']);

    $dev = (new ApnsCertificateInspector)->fromPem(CertificateFactory::make('Apple Development IOS Push Services: a.b', 'T1')['cert']);
    $prod = (new ApnsCertificateInspector)->fromPem(CertificateFactory::make('Apple Production IOS Push Services: a.b', 'T1')['cert']);
    expect($check->sameIdentity(CredentialIdentity::apnsCertificate($dev), CredentialIdentity::apnsCertificate($prod))->hasErrors())->toBeTrue();
});

it('two connections, one key: the same .p8 gives the same public fingerprint, another key does not', function () {
    $inspector = new ApnsKeyInspector;
    $key = TestKeys::ec()['pem'];
    $reformatted = str_replace("\n", "\r\n", $key);

    $a = $inspector->inspect($key);
    $b = $inspector->inspect($reformatted);
    $c = $inspector->inspect(TestKeys::ec()['pem']);

    expect($a->publicFingerprintSha256)->toBe($b->publicFingerprintSha256)
        ->and($c->publicFingerprintSha256)->not->toBe($a->publicFingerprintSha256);

    $check = new CredentialCheck(new FakeClock);
    expect($check->apnsKey($a)->passes())->toBeTrue()
        ->and($check->apnsKey($inspector->inspect(TestKeys::ec('secp384r1')['pem']))->codes(Severity::Error))->toBe(['apns.key.not_p256']);
});

it('a service-account file pointing elsewhere: warning only, and the official endpoint is the one used', function () {
    $json = (string) json_encode([
        'type' => 'service_account', 'project_id' => 'p', 'private_key_id' => 'k', 'client_email' => 'a@p.iam.gserviceaccount.com',
        'private_key' => TestKeys::rsa()['pem'], 'token_uri' => 'https://evil.example/token',
    ]);
    $info = (new ServiceAccountInspector)->fromJson($json);
    $result = (new CredentialCheck(new FakeClock))->serviceAccount($info);

    expect($result->codes(Severity::Warning))->toBe(['fcm.service_account.token_uri_ignored'])
        ->and($result->hasErrors())->toBeFalse()
        ->and($result->find('fcm.service_account.token_uri_ignored')?->message)->toContain('https://oauth2.googleapis.com/token')
        ->and(var_export($result, true))->not->toContain('PRIVATE KEY');

    // The credentials object the provider uses carries the file's token_uri, but the provider ignores it.
    $creds = ServiceAccountCredentials::fromJson($json);
    expect($creds->tokenUri)->toBe('https://evil.example/token')
        ->and(ServiceAccountTokenProvider::TOKEN_ENDPOINT)->toBe('https://oauth2.googleapis.com/token');

    $broken = (new CredentialCheck(new FakeClock))->serviceAccount((new ServiceAccountInspector)->fromJson('{"type":"authorized_user"}'));
    expect($broken->codes(Severity::Error))->toContain('fcm.service_account.missing_fields')->toContain('fcm.service_account.wrong_type');
});
