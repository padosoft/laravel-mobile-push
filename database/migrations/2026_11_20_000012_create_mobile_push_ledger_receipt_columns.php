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
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.ledger.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table): void {
            $table->char('message_id', 36)->nullable();
            $table->boolean('receipt_marker')->default(false);
            $table->index(['scope', 'message_id'], 'mp_deliveries_message_id_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table): void {
            $table->dropIndex('mp_deliveries_message_id_idx');
            $table->dropColumn(['message_id', 'receipt_marker']);
        });
    }

    private function table(): string
    {
        $table = config('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_deliveries';
    }
};
