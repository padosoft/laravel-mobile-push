<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message\Support;

final class Truncator
{
    public const MODE_INTL = 'intl';

    public const MODE_PCRE = 'pcre';

    /** @var self::MODE_*|null null = automatic (intl when available, PCRE otherwise) */
    private static ?string $forcedMode = null;

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
     * @return list<string> extended grapheme clusters (an emoji ZWJ sequence is one element); empty for
     *                      empty or invalid UTF-8 input
     */
    public static function graphemes(string $text): array
    {
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return [];
        }

        return self::mode() === self::MODE_INTL ? self::intlGraphemes($text) : self::pcreGraphemes($text);
    }

    /**
     * Pick the segmentation path explicitly (tests) or restore automatic selection with null.
     * Forcing intl when the extension is missing is ignored: the PCRE path is used.
     *
     * @param  self::MODE_*|null  $mode
     */
    public static function forceMode(?string $mode): void
    {
        self::$forcedMode = $mode;
    }

    /** @return self::MODE_* the path that is actually used right now */
    public static function mode(): string
    {
        $intl = function_exists('grapheme_extract');

        return match (self::$forcedMode) {
            self::MODE_PCRE => self::MODE_PCRE,
            default => $intl ? self::MODE_INTL : self::MODE_PCRE,
        };
    }

    /** @return list<string> */
    private static function pcreGraphemes(string $text): array
    {
        if (preg_match_all('/\X/u', $text, $matches) === false) {
            return [];
        }

        return $matches[0];
    }

    /** @return list<string> */
    private static function intlGraphemes(string $text): array
    {
        $result = [];
        $length = strlen($text);
        $start = 0;

        while ($start < $length) {
            $next = 0;
            $grapheme = grapheme_extract($text, 1, GRAPHEME_EXTR_COUNT, $start, $next);
            if ($grapheme === false || $grapheme === '' || ! is_int($next) || $next <= $start) {
                break;
            }
            $result[] = $grapheme;
            $start = $next;
        }

        return $result;
    }
}
