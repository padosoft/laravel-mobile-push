<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\DerSignature;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * "The APNs key lives in an HSM and cannot be taken out of it." The key pair is generated inside a PKCS#11 token and
 * its private half is marked never extractable; the signer below asks the token for an ECDSA signature of the SHA-256
 * digest and gets the raw R||S form a JWT needs. The provider token the transport would send is then verified against
 * the public key read from the token.
 *
 * It runs where a PKCS#11 module is announced: MOBILE_PUSH_TEST_PKCS11=<path of the module> with `softhsm2-util` and
 * `pkcs11-tool` (packages softhsm2 and opensc) on the PATH; the `backends` CI workflow installs SoftHSM 2. SoftHSM is
 * a software token: the PKCS#11 interface and the non-extractable key are real, the tamper-proof hardware is not, and
 * no cloud KMS is involved.
 */
final class Pkcs11ApnsSigner implements Signer
{
    public int $calls = 0;

    /** @param array<string, string> $env */
    public function __construct(
        private readonly string $module,
        private readonly string $tokenLabel,
        private readonly string $pin,
        private readonly string $objectId,
        private readonly string $apnsKeyId,
        private readonly string $workDir,
        private readonly array $env,
    ) {}

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
        $digest = $this->workDir.'/digest-'.$this->calls.'.bin';
        $signature = $this->workDir.'/signature-'.$this->calls.'.bin';
        file_put_contents($digest, hash('sha256', $signingInput, true));
        // Mechanism ECDSA signs the digest it is given and answers R||S (64 bytes on P-256), not DER.
        [$code, $output] = hsmRun([
            'pkcs11-tool', '--module', $this->module, '--token-label', $this->tokenLabel, '--login', '--pin', $this->pin,
            '--sign', '--mechanism', 'ECDSA', '--id', $this->objectId, '--input-file', $digest, '--output-file', $signature,
        ], $this->env);
        if ($code !== 0 || ! is_file($signature)) {
            throw new AuthenticationException('The PKCS#11 token did not sign: '.$output);
        }

        return (string) file_get_contents($signature);
    }
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 * @return array{int, string} exit code and combined output
 */
function hsmRun(array $command, array $env): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
    if (! is_resource($process)) {
        return [127, 'could not start '.$command[0]];
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

beforeEach(function (): void {
    $module = getenv('MOBILE_PUSH_TEST_PKCS11');
    if (! is_string($module) || $module === '' || ! is_file($module)) {
        $this->markTestSkipped('Set MOBILE_PUSH_TEST_PKCS11=<path of a PKCS#11 module, e.g. libsofthsm2.so> (softhsm2-util and pkcs11-tool installed) to run the HSM signer.');
    }
    $this->module = $module;
    $this->dir = sys_get_temp_dir().'/mp-hsm-'.bin2hex(random_bytes(5));
    mkdir($this->dir.'/tokens', 0700, true);
    file_put_contents($this->dir.'/softhsm2.conf', "directories.tokendir = {$this->dir}/tokens\nobjectstore.backend = file\nlog.level = ERROR\n");
    $this->env = ['SOFTHSM2_CONF' => $this->dir.'/softhsm2.conf'];
    $this->pin = (string) random_int(100000, 999999);

    [$init] = hsmRun(['softhsm2-util', '--init-token', '--free', '--label', 'mobile-push', '--pin', $this->pin, '--so-pin', (string) random_int(10000000, 99999999)], $this->env);
    // A P-256 key pair generated inside the token: the private half never exists in this process.
    [$generated] = hsmRun(['pkcs11-tool', '--module', $module, '--token-label', 'mobile-push', '--login', '--pin', $this->pin, '--keypairgen', '--key-type', 'EC:prime256v1', '--id', '01', '--label', 'apns'], $this->env);
    if ($init !== 0 || $generated !== 0) {
        $this->markTestSkipped('The PKCS#11 token could not be prepared (softhsm2-util / pkcs11-tool).');
    }
});

afterEach(function (): void {
    if (isset($this->dir) && is_dir($this->dir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }
});

it('signs the APNs provider token inside a PKCS#11 token whose key is never extractable: the JWT on the wire verifies against the token\'s public key', function (): void {
    $world = W::install(['connections' => ['ios' => [
        'driver' => 'apns', 'auth' => 'token', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE, 'signer' => 'hsm.apns',
    ]]]);
    $signer = new Pkcs11ApnsSigner($this->module, 'mobile-push', $this->pin, '01', 'HSMKEY1234', $this->dir, $this->env);
    app()->instance('hsm.apns', $signer);

    $results = W::all(MobilePush::toToken(Platform::Ios, W::apnsToken('hsm-a'))->send(PushMessage::make('Order shipped')));
    $again = W::all(MobilePush::toToken(Platform::Ios, W::apnsToken('hsm-b'))->send(PushMessage::make('Order delivered')));

    $requests = $world->requestsTo('api.push.apple.com');
    [$header, $claims, $signature] = explode('.', substr($requests[0]->headers['authorization'], strlen('bearer ')));
    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));
    // The token hands out the public key as a DER SubjectPublicKeyInfo, and says what it allows on the private one.
    [$read] = hsmRun(['pkcs11-tool', '--module', $this->module, '--token-label', 'mobile-push', '--read-object', '--type', 'pubkey', '--id', '01', '--output-file', $this->dir.'/public.der'], $this->env);
    $public = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode((string) file_get_contents($this->dir.'/public.der')), 64, "\n")."-----END PUBLIC KEY-----\n";
    [, $objects] = hsmRun(['pkcs11-tool', '--module', $this->module, '--token-label', 'mobile-push', '--login', '--pin', $this->pin, '--list-objects', '--type', 'privkey'], $this->env);

    expect($read)->toBe(0)
        ->and($objects)->toContain('Private Key Object; EC')->toContain('never extractable')
        ->and($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($again[0]->status)->toBe(SendStatus::Accepted)
        ->and(json_decode($decode($header), true))->toMatchArray(['alg' => 'ES256', 'kid' => 'HSMKEY1234'])
        ->and(json_decode($decode($claims), true)['iss'])->toBe(W::TEAM)
        ->and(strlen($decode($signature)))->toBe(64)
        ->and(openssl_verify($header.'.'.$claims, DerSignature::fromJose($decode($signature)), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        // one signature by the token for both pushes: the provider token is minted once and reused
        ->and($signer->calls)->toBe(1)
        ->and($requests[1]->headers['authorization'])->toBe($requests[0]->headers['authorization']);
});

it('reports a token that refuses to sign (wrong PIN) as a failed send of that destination, with nothing on the wire', function (): void {
    $world = W::install(['connections' => ['ios' => [
        'driver' => 'apns', 'auth' => 'token', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE, 'signer' => 'hsm.apns',
    ]]]);
    app()->instance('hsm.apns', new Pkcs11ApnsSigner($this->module, 'mobile-push', '000000', '01', 'HSMKEY1234', $this->dir, $this->env));

    $result = W::all(MobilePush::toToken(Platform::Ios, W::apnsToken('hsm-c'))->send(PushMessage::make('Order shipped')))[0];

    // A per-destination result, not an exception: the push is known not sent, and no request left.
    expect($result->status)->toBe(SendStatus::Failed)
        ->and($result->reason?->code)->toBe(ReasonCode::AuthTokenInvalid)
        ->and((string) $result->reason?->hint)->toContain('Nothing was sent')
        ->and($world->requestsTo('api.push.apple.com'))->toHaveCount(0);
});
