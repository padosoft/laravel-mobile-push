<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\DerSignature;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\OpenSslRs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Integration\ManagerCredentialsSource;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * A KMS / HSM keeps the key: the connection names a Signer bound in the container instead of a key file. The test
 * signer wraps a key generated at runtime and counts its signatures, standing for the remote signing call.
 */
final class KmsLikeSigner implements Signer
{
    public int $signatures = 0;

    public function __construct(private readonly Signer $inner) {}

    public function algorithm(): string
    {
        return $this->inner->algorithm();
    }

    public function keyId(): ?string
    {
        return $this->inner->keyId();
    }

    public function sign(string $signingInput): string
    {
        $this->signatures++;

        return $this->inner->sign($signingInput);
    }
}

function kmsSigner(string $keyId = 'KMSKEY1234'): KmsLikeSigner
{
    return new KmsLikeSigner(new OpenSslEs256Signer(PrivateKey::fromPem(W::ecPem('kms')), $keyId));
}

/** @return array<string, mixed> an APNs connection with no key material at all */
function kmsApns(array $extra = []): array
{
    return array_replace([
        'driver' => 'apns', 'auth' => 'token', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE,
        'signer' => 'kms.apns', 'key' => ['path' => '/nonexistent/AuthKey.p8'],
    ], $extra);
}

it('signs the APNs token with the container signer: the JWT verifies against the public key and no key file is read', function () {
    $world = W::install(['connections' => ['ios' => kmsApns()]]);
    $signer = kmsSigner();
    app()->instance('kms.apns', $signer);
    $iphone = W::apnsToken('kms-iphone');

    $result = W::all(MobilePush::toToken(Platform::Ios, $iphone)->send(PushMessage::make('Order shipped')))[0];

    $request = $world->requestsTo('api.push.apple.com')[0];
    [$header, $claims, $signature] = explode('.', substr($request->headers['authorization'], strlen('bearer ')));
    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));
    $public = openssl_pkey_get_details(openssl_pkey_get_private(W::ecPem('kms')))['key'];

    expect($result->status)->toBe(SendStatus::Accepted)
        ->and(json_decode($decode($header), true))->toMatchArray(['alg' => 'ES256', 'kid' => 'KMSKEY1234'])
        ->and(json_decode($decode($claims), true)['iss'])->toBe(W::TEAM)
        ->and(strlen($decode($signature)))->toBe(64)
        ->and(openssl_verify($header.'.'.$claims, DerSignature::fromJose($decode($signature)), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($signer->signatures)->toBe(1)
        ->and(MobilePush::binding('ios')->generation)->toBe(ConnectionFactory::generation('token:'.W::TEAM.':KMSKEY1234', ''));
});

it('accepts a key_id that equals the signer\'s and refuses one that differs, before any request', function () {
    $world = W::install(['connections' => ['ios' => kmsApns(['key_id' => 'KMSKEY1234']), 'other' => kmsApns(['key_id' => 'ANOTHERKEY'])]]);
    app()->instance('kms.apns', kmsSigner());

    expect(MobilePush::binding('ios')->name)->toBe('ios')
        ->and(fn () => MobilePush::binding('other'))->toThrow(ConfigurationException::class, 'mobile-push.connections.other.key_id differs from the key id of the signer');
    expect($world->transport->sentCount())->toBe(0);
});

it('refuses a signer that cannot be resolved, is not a Signer, signs with the wrong algorithm or has no key id', function (string $name, callable $bind, string $message) {
    W::install(['connections' => ['ios' => kmsApns(['signer' => $name])]]);
    $bind();

    expect(fn () => MobilePush::binding('ios'))->toThrow(ConfigurationException::class, $message);
})->with([
    'not bound' => ['No\\Such\\Signer', static fn () => null, 'mobile-push.connections.ios.signer could not be resolved from the container'],
    'not a signer' => ['kms.apns', static fn () => app()->instance('kms.apns', new stdClass), 'resolved to stdClass, which does not implement'],
    'wrong algorithm' => ['kms.apns', static fn () => app()->instance('kms.apns', new OpenSslRs256Signer(PrivateKey::fromPem(W::rsaPem()), 'KMSKEY1234')), 'signs with RS256 but this credential needs ES256'],
    'no key id' => ['kms.apns', static fn () => app()->instance('kms.apns', new OpenSslEs256Signer(PrivateKey::fromPem(W::ecPem('kms')))), 'must report a key id'],
]);

it('still needs the team id with a signer', function () {
    W::install(['connections' => ['ios' => kmsApns(['team_id' => null])]]);
    app()->instance('kms.apns', kmsSigner());

    expect(fn () => MobilePush::binding('ios'))->toThrow(ConfigurationException::class, 'mobile-push.connections.ios.team_id is required');
});

it('reports a signer connection as "external signer: expiry not verifiable" instead of failing', function () {
    W::install(['connections' => ['ios' => kmsApns()]]);
    app()->instance('kms.apns', kmsSigner());

    $source = new ManagerCredentialsSource(static fn (): PushManager => app(PushManager::class), static fn (): CredentialCheck => app(CredentialCheck::class));
    $connections = $source->connections();
    $messages = array_map(static fn ($f): string => $f->message, $connections[0]->evaluate(app(CredentialCheck::class))->findings);

    expect($connections)->toHaveCount(1)
        ->and($connections[0]->error)->toBeNull()
        ->and($connections[0]->apnsKey)->toBeNull()
        ->and(implode(' ', $messages))->toContain('expiry not verifiable')
        ->and($connections[0]->evaluate(app(CredentialCheck::class))->worst()->value)->toBe('ok');
});
