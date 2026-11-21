<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/**
 * Epoch milliseconds and typed reads of a stored campaign row (drivers return integers as strings, SQLite booleans
 * as 0/1).
 *
 * @internal
 */
final class Millis
{
    public static function of(\DateTimeInterface $at): int
    {
        return $at->getTimestamp() * 1000 + intdiv((int) $at->format('u'), 1000);
    }

    public static function date(?int $ms): ?\DateTimeImmutable
    {
        if ($ms === null) {
            return null;
        }
        $seconds = intdiv($ms, 1000);
        $rest = $ms % 1000;

        return (new \DateTimeImmutable('@'.$seconds))->modify(sprintf('+%d microseconds', $rest * 1000));
    }

    /** @param  array<string, mixed>  $row */
    public static function int(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param  array<string, mixed>  $row */
    public static function str(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  object|array<mixed>  $row
     * @return array<string, mixed>
     */
    public static function row(object|array $row): array
    {
        $data = [];
        foreach ((array) $row as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }
}
