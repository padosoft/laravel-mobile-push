<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * ULID generator (26 chars, Crockford base32): 48-bit millisecond timestamp + 80 random bits.
 * Ids are generated BEFORE any dispatch, so a crash after the write and before the dispatch never loses them.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(\DateTimeImmutable $at): string
    {
        $ms = LedgerTime::ms($at);
        $time = '';
        for ($i = 9; $i >= 0; $i--) {
            $time = self::ALPHABET[$ms % 32].$time;
            $ms = intdiv($ms, 32);
        }

        $random = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[ord($bytes[$i]) % 32];
        }

        return $time.$random;
    }

    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1;
    }
}
