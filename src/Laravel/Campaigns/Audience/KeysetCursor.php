<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Padosoft\MobilePush\Laravel\Campaigns\CampaignException;

/**
 * Cursor and upper-bound encoding of the built-in audiences: `<kind>:<non-negative integer>`, e.g. `id:4711` (last
 * registry id read), `i:20` (list offset), `b:8192` (file byte offset).
 *
 * The kind keeps a cursor of one audience from being read by another; decoding is strict (no sign, no leading
 * zero, no whitespace, no overflow), so a corrupted value stops the campaign instead of restarting it from zero.
 */
final class KeysetCursor
{
    public static function encode(string $kind, int $position): string
    {
        if ($position < 0 || preg_match('/^[a-z]{1,8}$/D', $kind) !== 1) {
            throw new \InvalidArgumentException('A cursor is a lowercase kind and a non-negative position.');
        }

        return $kind.':'.$position;
    }

    /** The position of `$cursor` (null = the start, 0). */
    public static function decode(string $kind, ?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/^'.preg_quote($kind, '/').':(0|[1-9][0-9]{0,18})$/D', $cursor, $m) !== 1) {
            throw new CampaignException('The stored campaign cursor is not a valid "'.$kind.'" cursor.');
        }
        $value = filter_var($m[1], FILTER_VALIDATE_INT);
        if (! is_int($value)) {
            throw new CampaignException('The stored campaign cursor is out of range.');
        }

        return $value;
    }
}
