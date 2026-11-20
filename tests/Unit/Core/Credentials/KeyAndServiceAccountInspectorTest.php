<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Credentials\ApnsKeyInspector;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInspector;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;

it('recognises an EC P-256 key and exposes only a public fingerprint', function () {
    $key = TestKeys::ec();
    $info = (new ApnsKeyInspector)->inspect($key['pem']);

    expect($info->isEcP256)->toBeTrue()
        ->and($info->curve)->toBe('prime256v1')
        ->and($info->publicFingerprintSha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and(var_export($info, true))->not->toContain('PRIVATE')
        ->and(json_encode($info))->not->toContain('PRIVATE');
});

it('flags keys of the wrong kind and refuses garbage', function () {
    $inspector = new ApnsKeyInspector;

    expect($inspector->inspect(TestKeys::ec('secp384r1')['pem'])->isEcP256)->toBeFalse()
        ->and($inspector->inspect(TestKeys::rsa()['pem'])->isEcP256)->toBeFalse()
        ->and(fn () => $inspector->inspect('-----BEGIN PRIVATE KEY-----\nabc'))->toThrow(ConfigurationException::class);
});

/** @param array<string, mixed> $override */
function saJson(array $override = [], ?string $pem = null): string
{
    $pem ??= TestKeys::rsa()['pem'];

    return (string) json_encode(array_filter([
        'type' => 'service_account',
        'project_id' => 'demo-project',
        'private_key_id' => 'kid123',
        'private_key' => $pem,
        'client_email' => 'push@demo-project.iam.gserviceaccount.com',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        ...$override,
    ], static fn ($v) => $v !== null));
}

it('reads a healthy service account', function () {
    $info = (new ServiceAccountInspector)->fromJson(saJson());

    expect($info->missingFields)->toBe([])
        ->and($info->typeIsServiceAccount())->toBeTrue()
        ->and($info->keyIsStrongRsa())->toBeTrue()
        ->and($info->clientEmail)->toBe('push@demo-project.iam.gserviceaccount.com')
        ->and($info->projectId)->toBe('demo-project')
        ->and($info->tokenUriIsOfficial)->toBeTrue()
        ->and(var_export($info, true))->not->toContain('BEGIN');
});

it('reports every variant of a broken file', function (array $override, callable $assert) {
    $assert((new ServiceAccountInspector)->fromJson(saJson($override)));
})->with([
    'missing project' => [['project_id' => null], fn ($i) => expect($i->missingFields)->toBe(['project_id'])],
    'wrong type' => [['type' => 'authorized_user'], fn ($i) => expect($i->typeIsServiceAccount())->toBeFalse()],
    'unparseable key' => [['private_key' => 'nope'], fn ($i) => expect($i->keyParses)->toBeFalse()],
    'ec key' => [['private_key' => TestKeys::ec()['pem']], fn ($i) => expect($i->keyIsStrongRsa())->toBeFalse()],
    'other token uri' => [['token_uri' => 'https://evil.example/token'], fn ($i) => expect($i->tokenUriIsOfficial)->toBeFalse()],
    'no token uri' => [['token_uri' => null], fn ($i) => expect($i->tokenUriIsOfficial)->toBeTrue()],
]);

it('refuses text that is not a JSON object', function (string $text) {
    expect(fn () => (new ServiceAccountInspector)->fromJson($text))->toThrow(ConfigurationException::class);
})->with(['{nope', '"x"', '12']);
