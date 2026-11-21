<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Receipts: two columns on the deliveries table. Durable profile only.
 *  - message_id: the sender-generated UUID of the last attempt (what a receipt marker carries). The provider's own
 *    id (provider_message_id) is not usable for this: FCM answers with its own name.
 *  - receipt_marker: true when a marker was embedded in the payload (the delivery could report), or when a client
 *    report arrived (which proves one was).
 * The client events themselves use the existing client_received_at / client_opened_at columns: first write wins.
 * Published with: php artisan vendor:publish --tag=mobile-push-migrations-ledger
 */
return new class extends Migration
{
    private const INDEX = 'mp_deliveries_message_id_idx';

    public function getConnection(): ?string
    {
        $connection = config('mobile-push.ledger.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = $this->table();

        // Idempotent: every step checks what already exists, so a second run (a published copy next to an auto-loaded
        // file) changes nothing. Without the deliveries table there is nothing to extend (the effects/deliveries
        // migrations run first).
        if (! $schema->hasTable($table)) {
            return;
        }

        $columns = [];
        if (! $schema->hasColumn($table, 'message_id')) {
            $columns[] = 'message_id';
        }
        if (! $schema->hasColumn($table, 'receipt_marker')) {
            $columns[] = 'receipt_marker';
        }
        $index = $this->hasIndex($schema, $table);

        if ($columns === [] && $index) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($columns, $index): void {
            if (in_array('message_id', $columns, true)) {
                $blueprint->char('message_id', 36)->nullable();
            }
            if (in_array('receipt_marker', $columns, true)) {
                $blueprint->boolean('receipt_marker')->default(false);
            }
            if (! $index) {
                $blueprint->index(['scope', 'message_id'], self::INDEX);
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = $this->table();

        if (! $schema->hasTable($table)) {
            return;
        }

        // The index goes first: SQLite cannot drop a column that an index still uses.
        if ($this->hasIndex($schema, $table)) {
            $schema->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(self::INDEX);
            });
        }

        $present = array_values(array_filter(
            ['message_id', 'receipt_marker'],
            static fn (string $column): bool => $schema->hasColumn($table, $column),
        ));
        if ($present !== []) {
            $schema->table($table, function (Blueprint $blueprint) use ($present): void {
                $blueprint->dropColumn($present);
            });
        }
    }

    private function hasIndex(\Illuminate\Database\Schema\Builder $schema, string $table): bool
    {
        foreach ($schema->getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }

    private function table(): string
    {
        $table = config('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_deliveries';
    }
};
