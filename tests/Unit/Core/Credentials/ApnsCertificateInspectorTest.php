<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Credentials\ApnsCertificateInspector;
use Padosoft\MobilePush\Core\Credentials\ApnsCertificateKind;
use Padosoft\MobilePush\Core\Credentials\CertificateInfo;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Tests\Unit\Core\Credentials\CertificateFactory;

function certInfo(string $notAfter): CertificateInfo
{
    return new CertificateInfo('cn', 'T', new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable($notAfter), str_repeat('a', 64), [], ApnsCertificateKind::Unknown, null);
}

it('computes validity at the boundaries', function (string $now, int $days, bool $expired) {
    $info = certInfo('2026-12-20T10:00:00Z');
    $at = new DateTimeImmutable($now);

    expect($info->daysRemaining($at))->toBe($days)->and($info->isExpired($at))->toBe($expired);
})->with([
    'exactly at expiry' => ['2026-12-20T10:00:00Z', 0, true],
    'one second before' => ['2026-12-20T09:59:59Z', 0, false],
    'in 30 days' => ['2026-11-20T10:00:00Z', 30, false],
    'in 31 days' => ['2026-11-19T10:00:00Z', 31, false],
    'one day after' => ['2026-12-21T10:00:00Z', -1, true],
    'one second after' => ['2026-12-20T10:00:01Z', -1, true],
]);

it('reads subject, team, validity and a stable lowercase SHA-256 fingerprint of the DER', function () {
    $made = CertificateFactory::make('Apple Push Services: com.example.app', 'TEAM123456');
    $a = (new ApnsCertificateInspector)->fromPem($made['cert']);
    $b = (new ApnsCertificateInspector)->fromPem($made['cert']);

    $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $made['cert']));
    expect($a->commonName)->toBe('Apple Push Services: com.example.app')
        ->and($a->teamId)->toBe('TEAM123456')
        ->and($a->fingerprintSha256)->toBe(hash('sha256', $der))->toBe($b->fingerprintSha256)
        ->and($a->fingerprintSha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($a->notAfter > $a->notBefore)->toBeTrue()
        ->and($a->keyMatches)->toBeNull();

    $other = (new ApnsCertificateInspector)->fromPem(CertificateFactory::make()['cert']);
    expect($other->fingerprintSha256)->not->toBe($a->fingerprintSha256);
});

it('decodes topics from Apple extension .4 and the universal marker from .6', function () {
    $made = CertificateFactory::make('Apple Push Services: com.example.app', topics: ['com.example.app', 'com.example.app.voip'], universal: true);
    $info = (new ApnsCertificateInspector)->fromPem($made['cert']);

    expect($info->topics)->toBe(['com.example.app', 'com.example.app.voip'])
        ->and($info->kind)->toBe(ApnsCertificateKind::Universal)
        ->and($info->environment())->toBeNull();
});

it('classifies the environment from the CN and never guesses', function (string $cn, ApnsCertificateKind $kind) {
    $info = (new ApnsCertificateInspector)->fromPem(CertificateFactory::make($cn)['cert']);

    expect($info->kind)->toBe($kind)->and($info->topics)->toBe([]);
})->with([
    ['Apple Development IOS Push Services: com.example.app', ApnsCertificateKind::Sandbox],
    ['Apple Production IOS Push Services: com.example.app', ApnsCertificateKind::Production],
    ['Apple Push Services: com.example.app', ApnsCertificateKind::Unknown],
]);

it('detects a private key that does not belong to the certificate', function () {
    $mine = CertificateFactory::make();
    $other = CertificateFactory::make();
    $inspector = new ApnsCertificateInspector;

    expect($inspector->fromPem($mine['cert'], $mine['key'])->keyMatches)->toBeTrue()
        ->and($inspector->fromPem($mine['cert'], $other['key'])->keyMatches)->toBeFalse()
        ->and($inspector->fromPem($mine['cert']."\n".$mine['key'])->keyMatches)->toBeTrue();
});

it('reads a PKCS#12 bundle and rejects a wrong passphrase without leaking it', function () {
    $made = CertificateFactory::make();
    $blob = ($made['pkcs12'])('right-pass');
    $inspector = new ApnsCertificateInspector;

    $info = $inspector->fromPkcs12($blob, 'right-pass');
    expect($info->keyMatches)->toBeTrue()
        ->and($info->fingerprintSha256)->toBe($inspector->fromPem($made['cert'])->fingerprintSha256);

    try {
        $inspector->fromPkcs12($blob, 'wrong-pass');
        $this->fail('expected exception');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->not->toContain('wrong-pass')->and($e->getMessage())->toContain('passphrase');
    }
});

it('rejects malformed PEM', function (string $input) {
    expect(fn () => (new ApnsCertificateInspector)->fromPem($input))->toThrow(ConfigurationException::class);
})->with([
    'empty' => [''],
    'garbage' => ['not a certificate'],
    'truncated block' => ["-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n"],
]);

it('marks every parameter that can carry a PEM, a private key or a passphrase as sensitive', function () {
    // With zend.exception_ignore_args=Off a trace would otherwise expose a combined cert+key file or the passphrase.
    $sensitive = ['pem', 'privateKeyPem', 'passphrase', 'blob', 'keyPem', 'certPem'];
    $unmarked = [];
    foreach ((new ReflectionClass(ApnsCertificateInspector::class))->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            if (in_array($parameter->getName(), $sensitive, true) && $parameter->getAttributes(SensitiveParameter::class) === []) {
                $unmarked[] = $method->getName().'($'.$parameter->getName().')';
            }
        }
    }

    expect($unmarked)->toBe([]);
});

it('replaces a combined certificate+key argument with a SensitiveParameterValue in exception traces', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        (new ApnsCertificateInspector)->fromPem("-----BEGIN PRIVATE KEY-----\nSECRET-KEY-MATERIAL\n-----END PRIVATE KEY-----\n", null, 'pass-phrase-secret');
        $this->fail('expected exception');
    } catch (ConfigurationException $e) {
        $frame = $e->getTrace()[0];
        expect($frame['function'])->toBe('fromPem')
            ->and($frame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
            ->and($frame['args'][2] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
            // Only the library's own frames: with exception_ignore_args off the runner's frames hold the whole
            // suite (circular references included), which var_export() cannot dump and this class cannot leak.
            ->and(var_export(array_values(array_filter($e->getTrace(), static fn (array $f): bool => str_starts_with($f['class'] ?? '', 'Padosoft\\MobilePush\\Core\\'))), true))
            ->not->toContain('SECRET-KEY-MATERIAL')->not->toContain('pass-phrase-secret');
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }
});
