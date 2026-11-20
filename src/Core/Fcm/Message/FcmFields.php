<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/**
 * Field-level validation and normalization shared by the FCM message builders.
 *
 * @internal
 */
final class FcmFields
{
    /**
     * A protobuf Duration as JSON: non-negative seconds with up to 9 fractional digits and the "s" suffix.
     *
     * @throws InvalidMessageException
     */
    public static function duration(int|float|string $value, string $field): string
    {
        if (is_string($value)) {
            if (preg_match('/^\d{1,12}(\.\d{1,9})?s$/', $value) !== 1) {
                throw new InvalidMessageException("{$field} must be a duration such as \"3600s\" or \"0.5s\", got \"{$value}\".");
            }

            return $value;
        }

        if (is_float($value) && (! is_finite($value) || $value < 0)) {
            throw new InvalidMessageException("{$field} must be a finite, non-negative number of seconds.");
        }
        if ($value < 0) {
            throw new InvalidMessageException("{$field} must not be negative.");
        }

        if (is_int($value)) {
            return $value.'s';
        }

        $text = rtrim(rtrim(number_format($value, 9, '.', ''), '0'), '.');

        return ($text === '' ? '0' : $text).'s';
    }

    /** @throws InvalidMessageException */
    public static function color(string $value): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new InvalidMessageException("color must be in #rrggbb format, got \"{$value}\".");
        }

        return $value;
    }

    /** @throws InvalidMessageException */
    public static function nonEmpty(string $value, string $field): string
    {
        if ($value === '') {
            throw new InvalidMessageException("{$field} must not be empty.");
        }

        return $value;
    }

    /** RFC 3339 UTC timestamp, as protobuf Timestamp expects it. */
    public static function timestamp(\DateTimeInterface|string $value, string $field): string
    {
        if ($value instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));

            return $utc->format('u') === '000000' ? $utc->format('Y-m-d\TH:i:s\Z') : $utc->format('Y-m-d\TH:i:s.u\Z');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidMessageException("{$field} must be an RFC 3339 timestamp, got \"{$value}\".");
        }

        return $value;
    }

    /**
     * Reserved data keys: "from", "message_type", and the prefixes "google." and "gcm.".
     *
     * The Discovery document names the prefix "gcm.notification."; we reject the whole "gcm." prefix, which is
     * what the older documentation says and is the safe superset.
     */
    public static function isReservedDataKey(string $key): bool
    {
        return $key === 'from' || $key === 'message_type' || str_starts_with($key, 'google.') || str_starts_with($key, 'gcm.');
    }

    /**
     * Deterministic stringification of a data map: strings as is, true/false as "true"/"false", integers in decimal,
     * floats in their shortest round-trip form ("1.5", "1" for 1.0), Stringable objects via __toString().
     * Null values are DROPPED and their keys returned in `dropped` (so the compiler can report them).
     * Arrays, other objects and non-finite floats are rejected.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{data: array<string, string>, dropped: list<string>}
     *
     * @throws InvalidMessageException
     */
    public static function stringifyData(array $data, string $field = 'data'): array
    {
        $out = [];
        $dropped = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                throw new InvalidMessageException("{$field} keys must not be empty.");
            }

            if ($value === null) {
                $dropped[] = $key;

                continue;
            }

            if (is_string($value)) {
                $out[$key] = $value;
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_int($value)) {
                $out[$key] = (string) $value;
            } elseif (is_float($value)) {
                if (! is_finite($value)) {
                    throw new InvalidMessageException("{$field} value for \"{$key}\" is not a finite number.");
                }
                $out[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            } elseif ($value instanceof \Stringable) {
                $out[$key] = (string) $value;
            } else {
                throw new InvalidMessageException("{$field} value for \"{$key}\" must be a scalar or Stringable, got ".get_debug_type($value).'.');
            }
        }

        return ['data' => $out, 'dropped' => $dropped];
    }

    /**
     * Data map for a builder: reserved keys are rejected immediately, nulls are dropped.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     *
     * @throws InvalidMessageException
     */
    public static function dataMap(array $data, string $field = 'data'): array
    {
        $normalized = self::stringifyData($data, $field)['data'];
        foreach (array_keys($normalized) as $key) {
            if (self::isReservedDataKey((string) $key)) {
                throw new InvalidMessageException("{$field} key \"{$key}\" is reserved by FCM (\"from\", \"message_type\", prefixes \"google.\" and \"gcm.\").");
            }
        }

        return $normalized;
    }
}
