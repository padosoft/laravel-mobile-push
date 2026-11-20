<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * Ledger times are stored as UTC epoch milliseconds (integers): exact, portable across SQLite, MySQL and
 * PostgreSQL, and immune to the query builder dropping fractional seconds from bound dates.
 *
 * @internal
 */
final class LedgerTime
{
    public static function ms(\DateTimeInterface $at): int
    {
        return $at->getTimestamp() * 1000 + intdiv((int) $at->format('u'), 1000);
    }

    public static function msOrNull(?\DateTimeInterface $at): ?int
    {
        return $at === null ? null : self::ms($at);
    }

    public static function date(int $ms): \DateTimeImmutable
    {
        $seconds = intdiv($ms, 1000);
        $rest = $ms % 1000;
        if ($rest < 0) {
            $seconds--;
            $rest += 1000;
        }

        return (new \DateTimeImmutable('@'.$seconds))->modify(sprintf('+%d microseconds', $rest * 1000));
    }

    public static function dateOrNull(?int $ms): ?\DateTimeImmutable
    {
        return $ms === null ? null : self::date($ms);
    }
}
