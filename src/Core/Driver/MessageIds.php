<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Identity\Destination;

/**
 * Provider correlation ids (APNs apns-id / apns-request-id): canonical lowercase UUIDs.
 *
 * With an effect key the id is a UUIDv5 derived from (binding identity, effect key, destination kind, destination
 * value), so a retried or resumed effect presents the same id; without one it is a random UUIDv4.
 */
final class MessageIds
{
    /** Namespace of this package's UUIDv5 ids (random, fixed forever). */
    public const NAMESPACE = '3b8f2a6c-5d1e-4c7a-9f20-8e4b6d1a7c35';

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function forEffect(string $bindingIdentity, string $effectKey, Destination $destination): string
    {
        $name = '';
        foreach ([$bindingIdentity, $effectKey, $destination->kind()->value, $destination->value()] as $field) {
            $name .= pack('N', strlen($field)).$field;   // length-prefixed: ("ab","c") never equals ("a","bc")
        }

        return self::v5(self::NAMESPACE, $name);
    }

    public static function random(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format($bytes);
    }

    /** RFC 9562 UUID version 5 (SHA-1 of namespace bytes + name). */
    public static function v5(string $namespace, string $name): string
    {
        if (! self::isCanonical(strtolower($namespace))) {
            throw new \InvalidArgumentException('The UUID namespace must be a canonical UUID.');
        }
        $hash = substr(sha1((string) hex2bin(str_replace('-', '', strtolower($namespace))).$name, true), 0, 16);
        $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x50);
        $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80);

        return self::format($hash);
    }

    public static function isCanonical(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
