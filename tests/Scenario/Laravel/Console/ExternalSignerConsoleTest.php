<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * An APNs connection whose key lives in a KMS (`signer`) has no key file and no key_id in the configuration: the
 * console must explain it as an external signer, not report a missing credential.
 */
function externalSignerWorld(): void
{
    W::install(['connections' => ['ios' => [
        'driver' => 'apns', 'auth' => 'token', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE, 'signer' => 'kms.apns',
    ]]]);
    app()->instance('kms.apns', new OpenSslEs256Signer(PrivateKey::fromPem(W::ecPem('kms-console')), 'KMSKEY1234'));
    C::sharedCache();
}

it('doctor reports a signer connection without a credential error and says its expiry cannot be verified', function () {
    externalSignerWorld();

    [$code, $out] = C::run('mobile-push:doctor', ['connection' => 'ios']);

    expect($code)->toBe(0)
        ->and($out)->not->toContain('key_id is required')
        ->not->toContain('✗')
        ->and(strtolower($out))->toContain('external signer: expiry not verifiable');
});

it('connections lists a signer connection with no credential error', function () {
    externalSignerWorld();

    [$code, $out] = C::run('mobile-push:connections', ['--json' => true]);
    $json = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($json['connections'][0]['name'])->toBe('ios')
        ->and($json['connections'][0]['error'] ?? null)->toBeNull()
        ->and(strtolower($out))->toContain('external signer');
});
