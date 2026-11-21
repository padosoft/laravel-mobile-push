<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Campaigns (durable profile only). Published with: php artisan vendor:publish --tag=mobile-push-migrations-campaigns
 * Table and connection: mobile-push.campaigns.table / mobile-push.campaigns.connection.
 * Times are UTC epoch milliseconds (BIGINT), like the ledger. The message itself is NOT here: it lives encrypted in
 * the ledger effect (effect_id); the audience parameters are stored encrypted. Every count shown for a campaign is
 * read from the ledger; `planned` is the planner's checkpoint, verified against the ledger by reconcile().
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.campaigns.connection');

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
            $table->char('id', 26)->primary();                        // ULID
            $table->string('scope', 64);
            $table->string('name', 191);

            // App binding (BindingRef::toArray() as JSON, no secret) and its identity.
            $table->text('binding');
            $table->char('binding_identity', 64);
            $table->char('effect_id', 26)->nullable();                // the ledger effect (message + deliveries)

            // Audience: class or alias, encrypted parameters, reach (individual | fan_out | mixed).
            $table->string('audience_type', 255);
            $table->longText('audience');
            $table->string('reach', 16);
            $table->string('title', 191)->nullable();                 // display only

            // Options: traffic class (always bulk), logical expiry, planner pacing, slice size, queue.
            $table->string('traffic_class', 16);
            $table->unsignedBigInteger('expires_at')->nullable();
            $table->unsignedInteger('rate_per_minute')->nullable();
            $table->unsignedInteger('slice_size');
            $table->string('queue_connection', 64)->nullable();
            $table->string('queue', 128)->nullable();

            // State and the planner checkpoint.
            $table->string('status', 16);
            $table->text('cursor')->nullable();
            $table->text('upper_bound')->nullable();
            $table->unsignedBigInteger('planned')->default(0);       // unique destinations written to the ledger
            $table->unsignedInteger('slices')->default(0);
            $table->unsignedBigInteger('invalid')->default(0);       // audience entries that were not a destination
            $table->unsignedInteger('successor_for')->nullable();     // slice count whose next planner job was queued
            $table->unsignedBigInteger('next_slice_at')->nullable(); // pacing
            $table->string('slice_owner', 191)->nullable();           // planner lease
            $table->unsignedBigInteger('slice_lease_until')->nullable();
            $table->unsignedBigInteger('version')->default(0);       // optimistic concurrency

            // Throughput sample for the remaining-time estimate, and the final counters.
            $table->unsignedBigInteger('sample_at')->nullable();
            $table->unsignedBigInteger('sample_done')->nullable();
            $table->double('throughput')->nullable();                 // terminal deliveries per second (estimate)
            $table->text('final_counts')->nullable();

            $table->unsignedBigInteger('started_at')->nullable();
            $table->unsignedBigInteger('planned_at')->nullable();
            $table->unsignedBigInteger('finished_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');

            $table->index(['scope', 'status'], 'mp_campaigns_scope_status_idx');
            $table->index(['scope', 'name'], 'mp_campaigns_scope_name_idx');
            $table->index(['status', 'updated_at'], 'mp_campaigns_status_updated_idx');
            $table->index(['status', 'finished_at'], 'mp_campaigns_status_finished_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.campaigns.table', 'mobile_push_campaigns');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_campaigns';
    }
};
