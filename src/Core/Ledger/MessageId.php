<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * The sender-visible message id of a delivery: a random RFC 4122 version 4 UUID, canonical lowercase. The ledger
 * assigns it when the delivery row is inserted, so it exists before any attempt (APNs `apns-id`, receipt markers).
 */
final class MessageId
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1;
    }
}
