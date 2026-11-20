<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

/**
 * Minimal, bounds-checked DER walker, just enough to find X.509 extensions and read the strings inside
 * Apple's push extensions. It never throws: malformed input yields empty results.
 *
 * @internal
 */
final class DerReader
{
    /**
     * Reads one TLV at `$offset`.
     *
     * @return array{tag: int, start: int, end: int}|null `start`/`end` delimit the content
     */
    public static function tlv(string $der, int $offset, int $limit): ?array
    {
        if ($offset + 2 > $limit) {
            return null;
        }
        $tag = ord($der[$offset]);
        $first = ord($der[$offset + 1]);
        $pos = $offset + 2;
        if ($first < 0x80) {
            $length = $first;
        } else {
            $count = $first & 0x7F;
            if ($count === 0 || $count > 4 || $pos + $count > $limit) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$pos + $i]);
            }
            $pos += $count;
        }
        if ($pos + $length > $limit) {
            return null;
        }

        return ['tag' => $tag, 'start' => $pos, 'end' => $pos + $length];
    }

    /**
     * Children TLVs of a constructed element's content.
     *
     * @return list<array{tag: int, start: int, end: int}>
     */
    public static function children(string $der, int $start, int $end): array
    {
        $out = [];
        $offset = $start;
        while ($offset < $end) {
            $item = self::tlv($der, $offset, $end);
            if ($item === null) {
                break;
            }
            $out[] = $item;
            $offset = $item['end'];
        }

        return $out;
    }

    /**
     * Extension values of a DER certificate keyed by dotted OID (the DER inside the OCTET STRING).
     *
     * @return array<string, string>
     */
    public static function certificateExtensions(string $der): array
    {
        $cert = self::tlv($der, 0, strlen($der));
        if ($cert === null || $cert['tag'] !== 0x30) {
            return [];
        }
        $tbs = self::children($der, $cert['start'], $cert['end'])[0] ?? null;
        if ($tbs === null || $tbs['tag'] !== 0x30) {
            return [];
        }

        $result = [];
        foreach (self::children($der, $tbs['start'], $tbs['end']) as $field) {
            if ($field['tag'] !== 0xA3) { // [3] EXPLICIT extensions
                continue;
            }
            $sequence = self::children($der, $field['start'], $field['end'])[0] ?? null;
            if ($sequence === null) {
                return [];
            }
            foreach (self::children($der, $sequence['start'], $sequence['end']) as $extension) {
                $parts = self::children($der, $extension['start'], $extension['end']);
                $oid = $parts[0] ?? null;
                $value = $parts[count($parts) - 1] ?? null;
                if ($oid === null || $value === null || $oid['tag'] !== 0x06 || $value['tag'] !== 0x04 || count($parts) < 2) {
                    continue;
                }
                $result[self::oid(substr($der, $oid['start'], $oid['end'] - $oid['start']))] = substr($der, $value['start'], $value['end'] - $value['start']);
            }
        }

        return $result;
    }

    /**
     * Every printable string (UTF8String, PrintableString, IA5String) at any depth, in order.
     *
     * @return list<string>
     */
    public static function strings(string $der, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        $out = [];
        $offset = 0;
        $length = strlen($der);
        while ($offset < $length) {
            $item = self::tlv($der, $offset, $length);
            if ($item === null) {
                break;
            }
            $content = substr($der, $item['start'], $item['end'] - $item['start']);
            if (in_array($item['tag'], [0x0C, 0x13, 0x16], true)) {
                $out[] = $content;
            } elseif (($item['tag'] & 0x20) !== 0) {
                array_push($out, ...self::strings($content, $depth + 1));
            }
            $offset = $item['end'];
        }

        return $out;
    }

    private static function oid(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $first = ord($bytes[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $value = 0;
        for ($i = 1, $n = strlen($bytes); $i < $n; $i++) {
            $byte = ord($bytes[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }
}
