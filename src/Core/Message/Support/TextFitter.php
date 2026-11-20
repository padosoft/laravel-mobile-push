<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message\Support;

final class TextFitter
{
    /**
     * Shorten human-readable text fields until the encoded payload fits `$limit` bytes.
     *
     * Only the fields passed here are ever shortened (title/subtitle/body): identifiers, URLs, custom data
     * and Live Activity state must never be given to this method. Fields are shortened in the given order.
     *
     * @param  array<string, string>  $fields  field name => text, in truncation order (first is shortened first)
     * @param  callable(array<string, string>): int  $measure  payload size in bytes for the given field values
     * @return array<string, string>|null the fitted fields, or null when the payload cannot fit
     */
    public static function fit(array $fields, int $limit, callable $measure, string $ellipsis = '…'): ?array
    {
        foreach (array_keys($fields) as $name) {
            // Each pass removes at least one byte, so this loop terminates.
            while (($overflow = $measure($fields) - $limit) > 0 && $fields[$name] !== '') {
                $target = strlen($fields[$name]) - $overflow;
                $shorter = Truncator::truncateToBytes($fields[$name], max(0, $target), $ellipsis);
                $fields[$name] = strlen($shorter) < strlen($fields[$name]) ? $shorter : '';
            }

            if ($measure($fields) <= $limit) {
                return $fields;
            }
        }

        return $measure($fields) <= $limit ? $fields : null;
    }
}
