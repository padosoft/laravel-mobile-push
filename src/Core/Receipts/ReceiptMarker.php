<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Receipts;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Privacy\KeyRing;

/**
 * The opaque receipt marker embedded in a payload (`mp_r`). It proves POSSESSION OF THE MARKER: not delivery, not
 * that a human read anything. One marker authorises the reports of one delivery.
 *
 * Wire format (then base64url without padding, URL- and JSON-safe):
 *
 *   byte  0      key version (1-255)
 *   bytes 1-4    expiry, unsigned 32-bit big-endian UNIX seconds
 *   byte  5      scope length L (1-64)
 *   bytes 6..    scope (ASCII), then the delivery reference (16 bytes: the sender's message UUID)
 *   last 12      tag = first 12 bytes of HMAC-SHA256(key[version], "padosoft/mobile-push receipt v1\0" . everything before)
 *
 * Size: 34 + L raw bytes, i.e. 4*ceil((34+L)*8/6) characters, unpadded: 55 for scope "default", 131 at the 64-char scope limit.
 *
 * Why a 12-byte (96-bit) tag: a forger cannot test guesses offline (the key is server-side) and online guesses go
 * through the rate-limited endpoint, so 2^96 is far beyond reach; NIST SP 800-107 accepts truncated HMAC down to
 * 32 bits for short-lived tags and recommends at least 64 for general use. Every byte counts: the marker lives in a
 * 4 KB APNs / FCM payload.
 *
 * The key ring is dedicated (independent of the index and encryption keys). Several versions verify, the current
 * one signs, so a rotation never invalidates markers still in flight. Comparisons are constant-time.
 */
final class ReceiptMarker
{
    public const TAG_BYTES = 12;

    public const MAX_LENGTH = 192;

    private const DOMAIN = "padosoft/mobile-push receipt v1\0";

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly KeyRing $keys)
    {
        foreach ($keys->versions() as $version) {
            if ($version > 255) {
                throw new ConfigurationException('Receipt key versions must be between 1 and 255.');
            }
        }
    }

    /**
     * @param  string  $deliveryReference  canonical lowercase UUID (the sender-generated message id)
     *
     * @throws \InvalidArgumentException for a scope or reference of the wrong shape, or an expiry outside 32 bits
     */
    public function issue(string $scope, string $deliveryReference, \DateTimeImmutable $expiresAt): string
    {
        $scope = Scope::normalize($scope);
        if (preg_match(self::UUID, $deliveryReference) !== 1) {
            throw new \InvalidArgumentException('The delivery reference must be a canonical lowercase UUID.');
        }
        $expiry = $expiresAt->getTimestamp();
        if ($expiry < 0 || $expiry > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('The marker expiry is out of range.');
        }

        $version = $this->keys->current;
        $body = chr($version).pack('N', $expiry).chr(strlen($scope)).$scope.(string) hex2bin(str_replace('-', '', $deliveryReference));

        return self::encode($body.$this->tag($version, $body));
    }

    /**
     * Null for anything that is not an authentic, unexpired marker (malformed, tampered, unknown key version, expired)
     * with no hint of which check failed.
     */
    public function verify(string $marker, \DateTimeImmutable $now, ?string $expectedScope = null): ?VerifiedMarker
    {
        if ($marker === '' || strlen($marker) > self::MAX_LENGTH) {
            return null;
        }
        $raw = self::decode($marker);
        // version 1 + expiry 4 + scope length 1 + scope >= 1 + reference 16 + tag 12
        if ($raw === null || strlen($raw) < 35) {
            return null;
        }

        $scopeLength = ord($raw[5]);
        if ($scopeLength < 1 || strlen($raw) !== 34 + $scopeLength) {
            return null;
        }

        $version = ord($raw[0]);
        $body = substr($raw, 0, -self::TAG_BYTES);
        $expected = $this->tag($version, $body);

        // Compute and compare before looking at anything else; an unknown key version still pays for one HMAC.
        if (! hash_equals($expected, substr($raw, -self::TAG_BYTES)) || $this->keys->key($version) === null) {
            return null;
        }

        $unpacked = unpack('Nexpiry', substr($raw, 1, 4));
        $expiry = is_array($unpacked) && is_int($unpacked['expiry'] ?? null) ? $unpacked['expiry'] : null;
        $scope = substr($raw, 6, $scopeLength);
        if ($expiry === null || $now->getTimestamp() >= $expiry
            || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $scope) !== 1
            || ($expectedScope !== null && ! hash_equals($expectedScope, $scope))) {
            return null;
        }

        $hex = bin2hex(substr($raw, 6 + $scopeLength, 16));
        $reference = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);

        return new VerifiedMarker($scope, $reference, (new \DateTimeImmutable)->setTimestamp($expiry), $version);
    }

    /** The key version a marker was issued under, without verifying it (diagnostics only; null when malformed). */
    public static function versionOf(string $marker): ?int
    {
        $raw = self::decode($marker);

        return $raw === null || $raw === '' ? null : ord($raw[0]);
    }

    private function tag(int $version, string $body): string
    {
        // An unknown version still runs one HMAC (with a throwaway key) so timing does not reveal the key set.
        $key = $this->keys->key($version) ?? str_repeat("\0", KeyRing::MIN_KEY_BYTES);

        return substr(hash_hmac('sha256', self::DOMAIN.$body, $key, true), 0, self::TAG_BYTES);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $marker): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $marker) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($marker, '-_', '+/'), true);

        // Canonical form only: one string per marker, no alternative paddings or trailing bits.
        return $raw === false || self::encode($raw) !== $marker ? null : $raw;
    }
}
