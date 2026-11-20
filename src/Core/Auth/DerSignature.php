<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/**
 * Converts between OpenSSL's DER ECDSA signature (SEQUENCE { INTEGER r, INTEGER s }) and the
 * fixed-width JWS concatenation R||S (RFC 7515 Appendix A.3).
 */
final class DerSignature
{
    /** Convert a DER signature to R||S, each component left-padded to `$componentLength` bytes. */
    public static function toJose(string $der, int $componentLength = 32): string
    {
        $length = strlen($der);
        $offset = 0;

        if ($length < 8 || $der[$offset++] !== "\x30") {
            throw new AuthenticationException('Malformed ECDSA signature: not a DER sequence.');
        }

        $sequenceLength = self::readLength($der, $offset);
        if ($offset + $sequenceLength !== $length) {
            throw new AuthenticationException('Malformed ECDSA signature: bad sequence length.');
        }

        $r = self::readInteger($der, $offset);
        $s = self::readInteger($der, $offset);
        if ($offset !== $length) {
            throw new AuthenticationException('Malformed ECDSA signature: trailing bytes.');
        }

        return self::fit($r, $componentLength).self::fit($s, $componentLength);
    }

    /** Convert R||S back to the DER encoding OpenSSL expects (inverse of {@see toJose()}). */
    public static function fromJose(string $jose): string
    {
        $length = strlen($jose);
        if ($length === 0 || $length % 2 !== 0) {
            throw new AuthenticationException('Malformed JOSE ECDSA signature: odd or empty length.');
        }

        $half = intdiv($length, 2);
        $body = self::encodeInteger(substr($jose, 0, $half)).self::encodeInteger(substr($jose, $half));

        return "\x30".self::encodeLength(strlen($body)).$body;
    }

    private static function readLength(string $der, int &$offset): int
    {
        if (! isset($der[$offset])) {
            throw new AuthenticationException('Malformed ECDSA signature: truncated.');
        }
        $first = ord($der[$offset++]);
        if ($first < 0x80) {
            return $first;
        }

        $bytes = $first & 0x7F;
        if ($bytes < 1 || $bytes > 2 || $offset + $bytes > strlen($der)) {
            throw new AuthenticationException('Malformed ECDSA signature: bad length encoding.');
        }
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($der[$offset++]);
        }
        if ($value < 0x80) {
            throw new AuthenticationException('Malformed ECDSA signature: non-minimal length.');
        }

        return $value;
    }

    private static function readInteger(string $der, int &$offset): string
    {
        if (! isset($der[$offset]) || $der[$offset] !== "\x02") {
            throw new AuthenticationException('Malformed ECDSA signature: expected an INTEGER.');
        }
        $offset++;
        $length = self::readLength($der, $offset);
        if ($length < 1 || $offset + $length > strlen($der)) {
            throw new AuthenticationException('Malformed ECDSA signature: bad INTEGER length.');
        }
        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    /** Strip DER sign padding (leading zeros), then left-pad to the fixed width. */
    private static function fit(string $integer, int $width): string
    {
        $integer = ltrim($integer, "\x00");
        if (strlen($integer) > $width) {
            throw new AuthenticationException('Malformed ECDSA signature: component too large for the curve.');
        }

        return str_pad($integer, $width, "\x00", STR_PAD_LEFT);
    }

    private static function encodeInteger(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\x00");
        if ($unsigned === '') {
            $unsigned = "\x00";
        }
        if (ord($unsigned[0]) >= 0x80) {
            $unsigned = "\x00".$unsigned;
        }

        return "\x02".self::encodeLength(strlen($unsigned)).$unsigned;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x100) {
            return "\x81".chr($length);
        }

        return "\x82".chr($length >> 8).chr($length & 0xFF);
    }
}
