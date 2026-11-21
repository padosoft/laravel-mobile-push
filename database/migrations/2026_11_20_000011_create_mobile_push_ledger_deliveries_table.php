<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Durable ledger: deliveries (one row per logical destination of an effect). Durable profile only.
 * Published with: php artisan vendor:publish --tag=mobile-push-migrations-ledger
 * Table and connection: mobile-push.ledger.tables.deliveries / mobile-push.ledger.connection.
 * Times are UTC epoch milliseconds (BIGINT). No clear token is ever stored here.
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
        $schema = Schema::connection($this->getConnection());

        // Idempotent: a second run (a published copy next to an auto-loaded file) must not fail on an existing table.
        if ($schema->hasTable($this->table())) {
            return;
        }

        $schema->create($this->table(), function (Blueprint $table): void {
            $table->char('id', 26)->primary();                          // ULID
            $table->char('effect_id', 26);
            $table->string('scope', 64);

            // Destination: logical key (registry device + generation, or HMAC index), never the clear token.
            $table->string('kind', 32);
            $table->string('logical_key', 191);
            $table->text('destination')->nullable();                   // encrypted raw value (not for registry devices)
            $table->string('device_id', 64)->nullable();
            $table->unsignedInteger('device_generation')->nullable();
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 64)->nullable();

            // State machine, attempts, retry budget, lease.
            $table->string('state', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('budget')->nullable();                        // RetryBudget::toArray() as JSON
            $table->boolean('unknown_seen')->default(false);
            $table->unsignedBigInteger('next_attempt_at')->nullable();
            $table->string('lease_owner', 191)->nullable();
            $table->unsignedBigInteger('lease_expires_at')->nullable();
            $table->unsignedBigInteger('attempt_started_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->boolean('cancel_requested')->default(false);

            // Copied from the effect so the claim query needs no join.
            $table->boolean('replaceable')->default(false);
            $table->unsignedBigInteger('expires_at')->nullable();
            $table->string('campaign_id', 64)->nullable();

            // Outcome.
            $table->string('provider_message_id', 128)->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->string('raw_reason', 120)->nullable();
            $table->text('hint')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('destination_action', 16)->default('none');
            $table->unsignedBigInteger('invalidated_at_ms')->nullable();
            $table->unsignedBigInteger('accepted_at')->nullable();
            $table->unsignedBigInteger('client_received_at')->nullable();  // filled by the receipts module
            $table->unsignedBigInteger('client_opened_at')->nullable();

            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');

            $table->unique(['effect_id', 'logical_key'], 'mp_deliveries_destination_unique');
            $table->index(['scope', 'state', 'next_attempt_at'], 'mp_deliveries_due_idx');
            $table->index(['scope', 'state', 'lease_expires_at'], 'mp_deliveries_lease_idx');
            $table->index(['scope', 'state', 'expires_at'], 'mp_deliveries_expiry_idx');
            $table->index(['effect_id', 'state'], 'mp_deliveries_effect_state_idx');
            $table->index(['effect_id', 'id'], 'mp_deliveries_effect_cursor_idx');
            $table->index(['scope', 'campaign_id', 'state'], 'mp_deliveries_campaign_state_idx');
            $table->index(['scope', 'owner_type', 'owner_id'], 'mp_deliveries_owner_idx');
            $table->index(['state', 'updated_at'], 'mp_deliveries_prune_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_deliveries';
    }
};
