<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

beforeEach(function () {
    $this->cipher = new TokenCipher(new KeyRing([1 => random_bytes(32)]));
});

it('round trips and never repeats a nonce', function () {
    $a = $this->cipher->encrypt('device-token-value', 'tenant', 'apns_device_token');
    $b = $this->cipher->encrypt('device-token-value', 'tenant', 'apns_device_token');

    expect($a)->toStartWith('v1:')->and($a)->not->toBe($b)
        ->and($a)->not->toContain('device-token-value')
        ->and($this->cipher->decrypt($a, 'tenant', 'apns_device_token'))->toBe('device-token-value');
});

it('detects tampering, wrong scope and wrong context', function () {
    $env = $this->cipher->encrypt('secret-value', 'tenant', 'ctx');
    $flipped = substr($env, 0, -2).(str_ends_with($env, 'AA') ? 'BB' : 'AA');

    expect(fn () => $this->cipher->decrypt($flipped, 'tenant', 'ctx'))->toThrow(PrivacyException::class)
        ->and(fn () => $this->cipher->decrypt($env, 'other', 'ctx'))->toThrow(PrivacyException::class)
        ->and(fn () => $this->cipher->decrypt($env, 'tenant', 'other'))->toThrow(PrivacyException::class)
        ->and(fn () => $this->cipher->decrypt('garbage', 'tenant'))->toThrow(PrivacyException::class);
});

it('rotates keys: old values still decrypt, new values use the new version', function () {
    $k1 = random_bytes(32);
    $old = (new TokenCipher(new KeyRing([1 => $k1])))->encrypt('value-1', 's');
    $rotated = new TokenCipher(new KeyRing([1 => $k1, 2 => random_bytes(32)]));

    expect($rotated->decrypt($old, 's'))->toBe('value-1')
        ->and($rotated->needsRotation($old))->toBeTrue()
        ->and($rotated->encrypt('value-1', 's'))->toStartWith('v2:');

    $dropped = new TokenCipher(new KeyRing([2 => random_bytes(32)]));
    expect(fn () => $dropped->decrypt($old, 's'))->toThrow(PrivacyException::class);
});

it('exception messages contain neither plaintext nor envelope', function () {
    $env = $this->cipher->encrypt('PLAINTEXT-CANARY', 'a');

    try {
        $this->cipher->decrypt($env, 'b');
    } catch (PrivacyException $e) {
        expect($e->getMessage())->not->toContain('PLAINTEXT-CANARY')->and($e->getMessage())->not->toContain($env);
    }
});

/** Ring used by the golden vectors below: obvious literal keys, never real material. */
function goldenRing(int $current = 0): KeyRing
{
    return new KeyRing([1 => str_repeat('k', 32), 2 => str_repeat('q', 32)], $current);
}

function envelopeBytes(string $envelope): string
{
    return (string) base64_decode(strtr(substr($envelope, strpos($envelope, ':') + 1), '-_', '+/'), true);
}

function envelopeOf(int $version, string $raw): string
{
    return 'v'.$version.':'.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

it('decrypts values written by earlier releases: the envelope, key derivation and associated data are frozen', function () {
    $cipher = new TokenCipher(goldenRing());

    expect($cipher->decrypt('v1:WY6iVZm7O88F5k3dICDj_ODhekpZ-CB88ALFDnDEEjwHE2VNwPlVrX8gD3EEdyw', 'tenant-a', 'apns_device_token'))->toBe('golden-device-token')
        ->and($cipher->decrypt('v2:Vd75wj8H1NAi03UIeefw8DFwARYF9Kd9eOaHfyniwFSxlFGNMtRHYs-OGmLvNeE', 'tenant-a', 'apns_device_token'))->toBe('golden-device-token');
});

it('writes v<current>: + base64url(12-byte nonce || 16-byte tag || ciphertext), without padding', function (string $plaintext) {
    $env = (new TokenCipher(goldenRing()))->encrypt($plaintext, 'tenant-a');

    expect($env)->toMatch('/^v2:[A-Za-z0-9_-]+$/')
        ->and(strlen(envelopeBytes($env)))->toBe(12 + 16 + strlen($plaintext))
        ->and((new TokenCipher(goldenRing()))->decrypt($env, 'tenant-a'))->toBe($plaintext);
})->with(['empty: exactly 28 bytes, the shortest valid value' => [''], 'one byte' => ['x'], 'token' => [str_repeat('ab', 32)]]);

it('encrypts with the current version, explicit or highest, and every ring version still decrypts', function () {
    $v1 = new TokenCipher(goldenRing(1));
    $v2 = new TokenCipher(goldenRing());
    $a = $v1->encrypt('value', 's', 'ctx');
    $b = $v2->encrypt('value', 's', 'ctx');

    expect($a)->toStartWith('v1:')->and($b)->toStartWith('v2:')
        ->and($v2->decrypt($a, 's', 'ctx'))->toBe('value')
        ->and($v1->decrypt($b, 's', 'ctx'))->toBe('value')
        ->and($v2->encrypt('value', 's', 'ctx'))->not->toBe($b);
});

it('fails closed on every malformed envelope', function (string $envelope, string $message) {
    expect(fn () => (new TokenCipher(goldenRing()))->decrypt($envelope, 'tenant-a'))->toThrow(PrivacyException::class, $message);
})->with([
    'empty string' => ['', 'Malformed encrypted value.'],
    'version 0' => ['v0:'.str_repeat('A', 40), 'Malformed encrypted value.'],
    'version of 5 digits' => ['v10000:'.str_repeat('A', 40), 'Malformed encrypted value.'],
    'no payload' => ['v1:', 'Malformed encrypted value.'],
    'standard base64 alphabet' => ['v1:'.str_repeat('A', 39).'+', 'Malformed encrypted value.'],
    'invalid base64 length (4n+1)' => ['v1:'.str_repeat('A', 41), 'Malformed encrypted value.'],
    '27 bytes: shorter than nonce + tag' => [envelopeOf(1, str_repeat("\0", 27)), 'Malformed encrypted value.'],
    '28 bytes of zeros: well formed but not authentic' => [envelopeOf(1, str_repeat("\0", 28)), 'Decryption failed'],
    'version not in the ring' => [envelopeOf(3, str_repeat("\0", 40)), 'unknown key version 3'],
]);

it('fails closed when any byte of nonce, tag or ciphertext changes, or the value is truncated', function (int $offset) {
    $cipher = new TokenCipher(goldenRing());
    $raw = envelopeBytes($cipher->encrypt('device-token-value', 'tenant-a', 'ctx'));

    if ($offset < 0) {
        $tampered = substr($raw, 0, $offset);
    } else {
        $tampered = $raw;
        $tampered[$offset] = chr(ord($raw[$offset]) ^ 0x01);
    }

    expect(fn () => $cipher->decrypt(envelopeOf(2, $tampered), 'tenant-a', 'ctx'))->toThrow(PrivacyException::class, 'Decryption failed');
})->with(['first nonce byte' => [0], 'last nonce byte' => [11], 'first tag byte' => [12], 'last tag byte' => [27], 'first ciphertext byte' => [28], 'truncated by one byte' => [-1]]);

it('fails closed with the right version number but other key material', function () {
    $env = (new TokenCipher(goldenRing()))->encrypt('value', 's');

    expect(fn () => (new TokenCipher(new KeyRing([1 => str_repeat('k', 32), 2 => str_repeat('Q', 32)])))->decrypt($env, 's'))
        ->toThrow(PrivacyException::class, 'Decryption failed');
});

it('binds the scope with its length, so moving characters between scope and context fails', function () {
    $cipher = new TokenCipher(goldenRing());
    $env = $cipher->encrypt('value', 'ab', 'c');

    expect(fn () => $cipher->decrypt($env, 'a', 'bc'))->toThrow(PrivacyException::class)
        ->and(fn () => $cipher->decrypt($env, 'ab|', 'c'))->toThrow(PrivacyException::class)
        ->and($cipher->decrypt($env, 'ab', 'c'))->toBe('value');
});

it('flags for rotation only values written with another version than the current one', function () {
    $cipher = new TokenCipher(goldenRing());

    expect($cipher->needsRotation($cipher->encrypt('v', 's')))->toBeFalse()
        ->and($cipher->needsRotation((new TokenCipher(goldenRing(1)))->encrypt('v', 's')))->toBeTrue()
        ->and((new TokenCipher(goldenRing(1)))->needsRotation('v1:AAAA'))->toBeFalse()
        ->and($cipher->needsRotation('garbage'))->toBeFalse()
        ->and($cipher->needsRotation(''))->toBeFalse();
});

it('computes keyed digests in a frozen format: k<version>:<64 hex>', function () {
    $cipher = new TokenCipher(goldenRing());

    expect($cipher->digest('payload', 'tenant-a', 'ctx', 1))->toBe('k1:4696fc71f47448c46b512245c7e73ea6dd57123471580459fc037123536715ff')
        ->and($cipher->digest('payload', 'tenant-a', 'ctx'))->toBe('k2:d516bec2385cf30016b0f46a49e853e9915e4d0f21b74c4fe60f144139dcdb3d')
        ->and($cipher->digest('payload', 'tenant-a'))->toBe('k2:b7d048bb4bed1f2a5bd2ae5b1fad2e373f1a5e78e238dd815023818c43733874')
        ->and($cipher->digest('payload', 'tenant-a', ''))->toBe($cipher->digest('payload', 'tenant-a'))
        ->and((new TokenCipher(new KeyRing([1 => str_repeat('k', 32)])))->digest('payload', 'tenant-a', 'ctx'))->toBe($cipher->digest('payload', 'tenant-a', 'ctx', 1))
        ->and(fn () => $cipher->digest('payload', 'tenant-a', 'ctx', 3))->toThrow(PrivacyException::class);
});

it('matches a digest with the version it names and nothing else', function () {
    $cipher = new TokenCipher(goldenRing());
    $old = $cipher->digest('payload', 'tenant-a', 'ctx', 1);
    $current = $cipher->digest('payload', 'tenant-a');

    expect($cipher->digestMatches($old, 'payload', 'tenant-a', 'ctx'))->toBeTrue()
        ->and($cipher->digestMatches($current, 'payload', 'tenant-a'))->toBeTrue()
        ->and($cipher->digestMatches($current, 'payload', 'tenant-a', ''))->toBeTrue()
        ->and($cipher->digestMatches($old, 'other', 'tenant-a', 'ctx'))->toBeFalse()
        ->and($cipher->digestMatches($old, 'payload', 'tenant-b', 'ctx'))->toBeFalse()
        ->and($cipher->digestMatches($old, 'payload', 'tenant-a', 'other'))->toBeFalse()
        ->and($cipher->digestMatches('k3:'.substr($old, 3), 'payload', 'tenant-a', 'ctx'))->toBeFalse()
        ->and($cipher->digestMatches(strtoupper($old), 'payload', 'tenant-a', 'ctx'))->toBeFalse()
        ->and($cipher->digestMatches(substr($old, 0, -1), 'payload', 'tenant-a', 'ctx'))->toBeFalse()
        ->and($cipher->digestMatches('garbage', 'payload', 'tenant-a', 'ctx'))->toBeFalse()
        ->and($cipher->digestMatches('', 'payload', 'tenant-a', 'ctx'))->toBeFalse();
});
