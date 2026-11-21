<?php

declare(strict_types=1);

use Aws\Kms\KmsClient;
use Padosoft\MobilePush\Core\Auth\DerSignature;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * "The APNs key lives in a KMS and never leaves it." The signer below is what docs/credentials-apns.md describes,
 * written against the AWS KMS API: the service hashes and signs, answers a DER signature, and the signer converts it
 * to the raw R||S form a JWT needs. The provider token the transport would send is then verified against the public
 * key the KMS publishes for the same key.
 *
 * It runs where a KMS endpoint is announced (the `backends` CI workflow starts a local emulator of the AWS KMS API
 * and sets MOBILE_PUSH_TEST_KMS; aws/aws-sdk-php must be installed). No real KMS is involved.
 */
final class AwsKmsApnsSigner implements Signer
{
    public int $calls = 0;

    public function __construct(private readonly KmsClient $kms, private readonly string $kmsKeyId, private readonly string $apnsKeyId) {}

    public function algorithm(): string
    {
        return 'ES256';
    }

    public function keyId(): ?string
    {
        return $this->apnsKeyId;
    }

    public function sign(string $signingInput): string
    {
        $this->calls++;
        $der = (string) $this->kms->sign([
            'KeyId' => $this->kmsKeyId,
            'Message' => $signingInput,
            'MessageType' => 'RAW',
            'SigningAlgorithm' => 'ECDSA_SHA_256',
        ])['Signature'];

        return DerSignature::toJose($der);
    }
}

beforeEach(function (): void {
    $endpoint = getenv('MOBILE_PUSH_TEST_KMS');
    if (! is_string($endpoint) || $endpoint === '' || ! class_exists(KmsClient::class)) {
        $this->markTestSkipped('Set MOBILE_PUSH_TEST_KMS=<endpoint of a KMS API> (and install aws/aws-sdk-php) to run the KMS signer.');
    }
    $this->kms = new KmsClient([
        'region' => 'us-east-1', 'version' => 'latest', 'endpoint' => $endpoint,
        'credentials' => ['key' => 'local', 'secret' => 'local'],
    ]);
    // A P-256 signing key created inside the KMS: the private half never exists in this process.
    $this->kmsKeyId = (string) $this->kms->createKey(['KeySpec' => 'ECC_NIST_P256', 'KeyUsage' => 'SIGN_VERIFY'])['KeyMetadata']['KeyId'];
});

it('signs the APNs provider token through the KMS API: the JWT on the wire verifies against the KMS public key', function (): void {
    $world = W::install(['connections' => ['ios' => [
        'driver' => 'apns', 'auth' => 'token', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE, 'signer' => 'kms.apns',
    ]]]);
    $signer = new AwsKmsApnsSigner($this->kms, $this->kmsKeyId, 'KMSKEY1234');
    app()->instance('kms.apns', $signer);

    $results = W::all(MobilePush::toToken(Platform::Ios, W::apnsToken('kms-a'))->send(PushMessage::make('Order shipped')));
    $again = W::all(MobilePush::toToken(Platform::Ios, W::apnsToken('kms-b'))->send(PushMessage::make('Order delivered')));

    $requests = $world->requestsTo('api.push.apple.com');
    [$header, $claims, $signature] = explode('.', substr($requests[0]->headers['authorization'], strlen('bearer ')));
    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));
    // The KMS publishes the public key as a DER SubjectPublicKeyInfo.
    $spki = (string) $this->kms->getPublicKey(['KeyId' => $this->kmsKeyId])['PublicKey'];
    $public = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";

    expect($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($again[0]->status)->toBe(SendStatus::Accepted)
        ->and(json_decode($decode($header), true))->toMatchArray(['alg' => 'ES256', 'kid' => 'KMSKEY1234'])
        ->and(json_decode($decode($claims), true)['iss'])->toBe(W::TEAM)
        ->and(strlen($decode($signature)))->toBe(64)
        ->and(openssl_verify($header.'.'.$claims, DerSignature::fromJose($decode($signature)), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        // one remote signature for both pushes: the token is minted once and reused
        ->and($signer->calls)->toBe(1)
        ->and($requests[1]->headers['authorization'])->toBe($requests[0]->headers['authorization']);
});
