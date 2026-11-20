<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Ledger;

use Illuminate\Database\Connection;

/**
 * How claimDue() makes sure two workers never obtain the same row.
 *
 *  - SkipLocked: a short transaction selects the due ids with `FOR UPDATE SKIP LOCKED` and leases them with one
 *    UPDATE. Concurrent workers skip each other's locked rows instead of waiting or failing. PostgreSQL 9.5+,
 *    MySQL 8.0.1+, MariaDB 10.6+.
 *  - Optimistic: select candidate ids WITHOUT locks, then lease each with `UPDATE ... WHERE id = ? AND version = ?
 *    AND state IN (pending, deferred)`; a row someone else took has a new version and updates 0 rows, so it is
 *    simply not ours. Correct on any database (SQLite serializes writers; it has no row locks to skip).
 *
 * Both paths are correct; SkipLocked avoids wasted candidate reads under heavy contention.
 */
enum ClaimStrategy: string
{
    case Auto = 'auto';
    case SkipLocked = 'skip_locked';
    case Optimistic = 'optimistic';

    public function resolve(Connection $connection): self
    {
        if ($this !== self::Auto) {
            return $this;
        }

        return match ($connection->getDriverName()) {
            'pgsql' => self::SkipLocked,
            'mysql', 'mariadb' => self::mysqlSupportsSkipLocked($connection) ? self::SkipLocked : self::Optimistic,
            default => self::Optimistic,
        };
    }

    private static function mysqlSupportsSkipLocked(Connection $connection): bool
    {
        try {
            $version = $connection->getServerVersion();
        } catch (\Throwable) {
            return false;
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }
        $numeric = sprintf('%d.%d.%d', $m[1], $m[2], $m[3]);

        return stripos($version, 'mariadb') !== false || $connection->getDriverName() === 'mariadb'
            ? version_compare($numeric, '10.6.0', '>=')
            : version_compare($numeric, '8.0.1', '>=');
    }
}
