<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

/**
 * Authenticated encryption for values at rest: AES-256-GCM through OpenSSL.
 *
 * Envelope: `v<version>:` + base64url(nonce(12) || tag(16) || ciphertext). A fresh random nonce is drawn per value.
 * The associated data binds the ciphertext to its scope (and an optional context such as the destination kind), so a
 * value copied to another scope or column fails authentication. The 32-byte AES key is derived from the ring key with
 * HKDF, so the same ring material is never used directly for two purposes.
 */
final readonly class TokenCipher
{
    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public function __construct(private KeyRing $keys)
    {
        if (! in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new PrivacyException('The OpenSSL build does not support aes-256-gcm.');
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $scope, string $context = ''): string
    {
        $version = $this->keys->current;
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->derive($version), OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($version, $scope, $context), self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new PrivacyException('Encryption failed.');
        }

        return 'v'.$version.':'.self::b64($nonce.$tag.$ciphertext);
    }

    /** @throws PrivacyException on malformed envelope, unknown key version, wrong scope/context or tampering */
    public function decrypt(string $envelope, string $scope, string $context = ''): string
    {
        if (preg_match('/^v([1-9][0-9]{0,3}):([A-Za-z0-9_-]+)$/', $envelope, $m) !== 1) {
            throw new PrivacyException('Malformed encrypted value.');
        }

        $version = (int) $m[1];
        $raw = base64_decode(strtr($m[2], '-_', '+/'), true);

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new PrivacyException('Malformed encrypted value.');
        }

        if ($this->keys->key($version) === null) {
            throw new PrivacyException("Encrypted value uses unknown key version {$version}.");
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES),
            'aes-256-gcm',
            $this->derive($version),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES),
            substr($raw, self::NONCE_BYTES, self::TAG_BYTES),
            $this->aad($version, $scope, $context),
        );

        if ($plaintext === false) {
            throw new PrivacyException('Decryption failed: wrong key, scope or context, or the value was tampered with.');
        }

        return $plaintext;
    }

    /** True when the value was written with an older key version than the current one (candidate for re-encryption). */
    public function needsRotation(string $envelope): bool
    {
        return preg_match('/^v([1-9][0-9]{0,3}):/', $envelope, $m) === 1 && (int) $m[1] !== $this->keys->current;
    }

    private function derive(int $version): string
    {
        $key = $this->keys->key($version) ?? throw new PrivacyException("Unknown cipher key version {$version}.");

        return hash_hkdf('sha256', $key, 32, 'padosoft/mobile-push token-cipher v1');
    }

    private function aad(int $version, string $scope, string $context): string
    {
        return 'mp1|'.$version.'|'.strlen($scope).':'.$scope.'|'.$context;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
