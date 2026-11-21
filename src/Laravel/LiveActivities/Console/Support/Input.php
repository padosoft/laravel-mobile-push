<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Parsing of what an operator types: JSON objects, instants, owners and numbers. Every failure is an
 * InvalidInputException whose message names the option and says what was expected.
 */
final class Input
{
    private const UNITS = [
        'second' => 1, 'sec' => 1, 's' => 1,
        'minute' => 60, 'min' => 60, 'm' => 60,
        'hour' => 3600, 'h' => 3600,
        'day' => 86400, 'd' => 86400,
        'week' => 604800, 'w' => 604800,
    ];

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/i';

    /**
     * A JSON OBJECT (never an array or a scalar). Null or an empty string means "not given".
     *
     * @return array<string, mixed>|null
     */
    public static function jsonObject(string $option, ?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidInputException("--{$option} is not valid JSON ({$e->getMessage()}). Pass a JSON object such as '{\"eta\":12}'.");
        }

        if (! $decoded instanceof \stdClass) {
            $got = is_array($decoded) ? 'a JSON list' : 'a JSON '.get_debug_type($decoded);

            throw new InvalidInputException("--{$option} must be a JSON object such as '{\"eta\":12}', not {$got}.");
        }

        /** @var array<string, mixed> */
        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * An instant: ISO-8601 with an explicit offset ("2026-11-21T10:30:00Z", "2026-11-21T12:30:00+02:00") or a
     * duration relative to now with a sign ("+10 minutes", "-1 hour", "+90s").
     */
    public static function instant(string $option, string $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $value = trim($value);

        if (preg_match('/^([+-])\s*(\d+)\s*([a-z]+?)s?$/i', $value, $m) === 1) {
            $unit = self::UNITS[strtolower($m[3])] ?? null;
            if ($unit === null) {
                throw new InvalidInputException("--{$option}: unknown time unit \"{$m[3]}\" in \"{$value}\". Use seconds, minutes, hours, days or weeks.");
            }
            $seconds = (int) $m[2] * $unit;

            return $now->modify(($m[1] === '-' ? '-' : '+').$seconds.' seconds');
        }

        if (preg_match(self::ISO_8601, $value) === 1) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                // falls through to the common message
            }
        }

        throw new InvalidInputException("--{$option} must be an ISO-8601 instant with its offset (2026-11-21T10:30:00Z) or a signed relative duration (+10 minutes), got \"{$value}\".");
    }

    /**
     * `Type:id` split at the LAST colon, so a class name with namespaces works.
     *
     * @return array{0: string, 1: string}
     */
    public static function owner(string $raw): array
    {
        $at = strrpos($raw, ':');
        if ($at === false) {
            throw new InvalidInputException('--owner must look like Type:id, for example "App\\Models\\User:42" or "user:42" for a morph-map alias.');
        }

        $type = trim(substr($raw, 0, $at));
        $id = trim(substr($raw, $at + 1));
        if ($type === '' || $id === '') {
            throw new InvalidInputException('--owner needs both a type and an id around the colon (Type:id).');
        }

        return [$type, $id];
    }

    /** The owner as a model instance carrying only its key (nothing is read from the owner's table). */
    public static function ownerModel(string $raw): Model
    {
        [$type, $id] = self::owner($raw);
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidInputException("--owner: \"{$type}\" is neither an Eloquent model class nor a morph-map alias.");
        }

        $model = new $class;
        $model->forceFill([$model->getKeyName() => $id]);
        $model->exists = true;

        return $model;
    }

    public static function positiveInt(string $option, mixed $value, int $min, int $max): int
    {
        if (! is_string($value) && ! is_int($value) || preg_match('/^\d+$/', (string) $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw new InvalidInputException("--{$option} must be a whole number between {$min} and {$max}.");
        }

        return (int) $value;
    }

    public static function float(string $option, string $value, float $min, float $max): float
    {
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new InvalidInputException("--{$option} must be a number between {$min} and {$max}.");
        }

        return (float) $value;
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
