<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Receipts;

/**
 * The reversible mapping between a ledger delivery id (26-char Crockford ULID) and a canonical UUID, so the sender
 * can use the delivery id as its message id (`SendItem::$providerMessageId`) and the marker then names the delivery
 * itself.
 *
 * Why it matters: a delivery whose outcome is unknown never gets its result recorded (that is what "unknown" means),
 * so nothing would link its marker to the row if the link were written at record time. With this mapping the link
 * exists before the request leaves and needs no secret lookup table. The UUID carries no version nibble (all 128 bits
 * are the id); it is a plain 8-4-4-4-12 lowercase hex string.
 */
final class DeliveryReference
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function messageIdFor(string $deliveryId): ?string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $deliveryId) !== 1) {
            return null;
        }
        $bits = '';
        foreach (str_split($deliveryId) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        if (! str_starts_with($bits, '00')) {
            return null;   // more than 128 bits: not representable (a ULID this package never generates)
        }

        $hex = '';
        foreach (str_split(substr($bits, 2), 8) as $byte) {
            $hex .= str_pad(dechex((int) bindec($byte)), 2, '0', STR_PAD_LEFT);
        }

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    public static function deliveryIdFor(string $messageId): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $messageId) !== 1) {
            return null;
        }
        $bits = '00';
        foreach (str_split(str_replace('-', '', $messageId)) as $nibble) {
            $bits .= str_pad(decbin((int) hexdec($nibble)), 4, '0', STR_PAD_LEFT);
        }

        $id = '';
        foreach (str_split($bits, 5) as $group) {
            $id .= self::ALPHABET[(int) bindec($group)];
        }

        return $id;
    }
}
