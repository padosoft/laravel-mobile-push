<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/*
 * Durable ledger: indexes that keep every hot query proportional to the work it returns, never to the size of the
 * table (W5-P scaling pass). Published with: php artisan vendor:publish --tag=mobile-push-migrations-ledger
 *
 *  - mp_deliveries_claim_idx (scope, state, next_attempt_at, id): the scope-wide claim reads each claimable state in
 *    index order (ORDER BY next_attempt_at, id without a sort of every due row). Replaces mp_deliveries_due_idx
 *    (its prefix).
 *  - mp_deliveries_effect_claim_idx (effect_id, scope, state, next_attempt_at, id): the same for the claim of one
 *    effect, plus counts(), the settlement probe ("anything left open?") and the cursor of one effect's states.
 *    Replaces mp_deliveries_effect_state_idx (its prefix minus scope).
 *  - mp_deliveries_work_idx (state, next_attempt_at, scope): scopesWithWork() and the scope-less health() read only
 *    due rows, however many terminal rows the table holds.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.ledger.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private const ADD = [
        'mp_deliveries_claim_idx' => ['scope', 'state', 'next_attempt_at', 'id'],
        'mp_deliveries_effect_claim_idx' => ['effect_id', 'scope', 'state', 'next_attempt_at', 'id'],
        'mp_deliveries_work_idx' => ['state', 'next_attempt_at', 'scope'],
    ];

    /** Indexes made redundant by ADD (each is a prefix of one of them), and the columns to rebuild them on down(). */
    private const REPLACED = [
        'mp_deliveries_due_idx' => ['scope', 'state', 'next_attempt_at'],
        'mp_deliveries_effect_state_idx' => ['effect_id', 'state'],
    ];

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
        $table = config('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_deliveries';
    }
};
