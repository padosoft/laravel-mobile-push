<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * Typed reads from a stored row (array or stdClass). Database drivers differ: MySQL may return integers as strings,
 * PostgreSQL returns booleans, SQLite returns 0/1. This normalizes them.
 *
 * @internal
 */
final class Row
{
    /** @return array<string, mixed> */
    public static function of(object|array $row): array
    {
        $data = [];
        foreach ((array) $row as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }

    /** @param array<string, mixed> $row */
    public static function str(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new \UnexpectedValueException("Ledger column {$column} is missing.");
    }

    /** @param array<string, mixed> $row */
    public static function strOrNull(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::str($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if ((is_string($value) && is_numeric($value)) || is_float($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Ledger column {$column} is not an integer.");
    }

    /** @param array<string, mixed> $row */
    public static function intOrNull(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function bool(array $row, string $column): bool
    {
        $value = $row[$column] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function json(array $row, string $column): ?array
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            throw new \UnexpectedValueException("Ledger column {$column} is not a JSON object.");
        }

        return self::of($decoded);
    }
}
