<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message\Support;

final class Truncator
{
    /**
     * Shorten `$text` to at most `$maxBytes` bytes (ellipsis included) without ever splitting a grapheme
     * cluster. Pure: the input is never modified. Returns '' when not even the ellipsis fits.
     */
    public static function truncateToBytes(string $text, int $maxBytes, string $ellipsis = '…'): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $budget = $maxBytes - strlen($ellipsis);
        if ($budget <= 0) {
            return '';
        }

        $result = '';
        foreach (self::graphemes($text) as $grapheme) {
            if (strlen($result) + strlen($grapheme) > $budget) {
                break;
            }
            $result .= $grapheme;
        }

        $result = rtrim($result);

        return $result === '' ? '' : $result.$ellipsis;
    }

    /**
     * @return list<string> extended grapheme clusters (an emoji ZWJ sequence is one element)
     */
    public static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/\X/u', $text, $matches) === false) {
            return [];
        }

        return $matches[0];
    }
}
