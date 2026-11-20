<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message\Support;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

final class JsonEncoder
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Compact JSON as sent on the wire. Unescaped unicode and slashes save real bytes against provider limits.
     *
     * @param  array<array-key, mixed>|\stdClass  $payload
     *
     * @throws InvalidMessageException invalid UTF-8 or a value JSON cannot represent
     */
    public static function encode(array|\stdClass $payload): string
    {
        try {
            return json_encode($payload, self::FLAGS);
        } catch (\JsonException $e) {
            throw new InvalidMessageException('The message cannot be encoded as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * A JSON object even when empty: PHP would encode an empty array as `[]`, which providers reject for maps
     * such as APNs "aps"/"content-state" or FCM "data".
     *
     * @param  array<array-key, mixed>  $map
     */
    public static function map(array $map): \stdClass
    {
        return (object) $map;
    }
}
