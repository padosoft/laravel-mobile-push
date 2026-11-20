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
