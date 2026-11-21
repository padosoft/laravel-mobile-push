<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/*
 * Device registry: the index of the keyset audience query of campaigns (W5-P scaling pass)
 *   where scope = ? and binding_identity = ? and state = 'active' and id > ? and id <= ? order by id limit n
 * Without it the query reads every active device and sorts them, once per slice. With it each slice reads only
 * its own rows, in order. Published with: php artisan vendor:publish --tag=mobile-push-migrations-devices
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.devices.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private const ADD = [
        'mp_devices_audience_idx' => ['scope', 'binding_identity', 'state', 'id'],
    ];

    /** Indexes made redundant by ADD (each is a prefix of one of them), and the columns to rebuild them on down(). */
    private const REPLACED = [];

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = $this->table();
        // Idempotent: a second run (a published copy next to an auto-loaded file) changes nothing.
        if (! $schema->hasTable($table)) {
            return;
        }
        $existing = $this->indexes($schema, $table);

        $add = array_diff_key(self::ADD, $existing);
        if ($add !== []) {
            $schema->table($table, function (Blueprint $blueprint) use ($add): void {
                foreach ($add as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
        $drop = array_keys(array_intersect_key(self::REPLACED, $existing));
        if ($drop !== []) {
            $schema->table($table, function (Blueprint $blueprint) use ($drop): void {
                foreach ($drop as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = $this->table();
        if (! $schema->hasTable($table)) {
            return;
        }
        $existing = $this->indexes($schema, $table);

        $restore = array_diff_key(self::REPLACED, $existing);
        $drop = array_keys(array_intersect_key(self::ADD, $existing));
        $schema->table($table, function (Blueprint $blueprint) use ($restore, $drop): void {
            foreach ($restore as $name => $columns) {
                $blueprint->index($columns, $name);
            }
            foreach ($drop as $name) {
                $blueprint->dropIndex($name);
            }
        });
    }

    /** @return array<string, true> */
    private function indexes(Builder $schema, string $table): array
    {
        $names = [];
        foreach ($schema->getIndexes($table) as $index) {
            if (is_string($index['name'] ?? null)) {
                $names[$index['name']] = true;
            }
        }

        return $names;
    }

    private function table(): string
    {
        $table = config('mobile-push.devices.table', 'mobile_push_devices');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_devices';
    }
};
