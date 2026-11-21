<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Live Activities. Published with: php artisan vendor:publish --tag=mobile-push-migrations-live-activities
 * Table and connection come from mobile-push.live_activities.table / mobile-push.live_activities.connection.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.live_activities.connection');

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
            $table->ulid('id')->primary();

            // Tenant, app binding and environment: the activity belongs to exactly one of each.
            $table->string('scope', 64);
            $table->string('binding_name', 128);
            $table->char('binding_identity', 64);
            $table->string('app_id', 255);
            $table->string('environment', 16);
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 64)->nullable();

            // Explicit mapping to the Swift ActivityAttributes type, and the schema version of attributes/content-state.
            $table->string('type_key', 191);
            $table->string('swift_name', 191);
            $table->unsignedInteger('type_version');
            $table->string('client_activity_id', 128)->nullable();   // Activity.id reported by the app
            $table->char('push_to_start_id', 26)->nullable();         // the push-to-start token row a remote start used

            // The update token of THIS activity (encrypted) + HMAC index + generation.
            $table->text('update_token')->nullable();
            $table->string('update_token_index', 80)->nullable();
            $table->unsignedInteger('token_generation')->default(0);
            $table->string('token_state', 16)->nullable();            // active | quarantined | invalidated
            $table->string('broadcast_channel_id', 256)->nullable();  // APNs channel id the activity is subscribed to

            $table->string('state', 16);                               // requested | active | ending | ended | expired
            $table->string('start_status', 32)->nullable();            // SendStatus of a remote start
            $table->string('closed_reason', 32)->nullable();

            // What was actually sent last (serialized transitions).
            $table->json('last_content_state')->nullable();
            $table->string('last_event', 16)->nullable();
            $table->string('last_status', 32)->nullable();
            $table->unsignedTinyInteger('last_priority')->nullable();
            $table->unsignedBigInteger('last_timestamp')->nullable(); // Unix seconds carried by the last request
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('last_accepted_at')->nullable();

            // Ordering of transitions: tickets are numbered at dispatch; only a newer ticket may be applied.
            $table->unsignedInteger('dispatch_seq')->default(0);
            $table->unsignedInteger('applied_seq')->default(0);
            $table->unsignedInteger('pending_seq')->nullable();
            $table->json('pending_payload')->nullable();
            $table->unsignedBigInteger('pending_due_at')->nullable();  // Unix second from which the pending transition may go out
            $table->unsignedInteger('pending_attempts')->default(0);

            // Server-side limiter of high-priority update attempts (never the device budget).
            $table->unsignedBigInteger('hp_window_started_at')->nullable();
            $table->unsignedInteger('hp_count')->default(0);

            $table->unsignedInteger('version')->default(1);            // optimistic concurrency

            // Three distinct facts about the end: local expiry, end accepted by APNs, state observed by the app.
            $table->dateTime('started_at')->nullable();
            $table->dateTime('stale_at')->nullable();
            $table->dateTime('local_expires_at')->nullable();
            $table->dateTime('end_requested_at')->nullable();
            $table->dateTime('end_accepted_at')->nullable();
            $table->dateTime('dismissal_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('client_reported_state', 16)->nullable();
            $table->dateTime('client_reported_at')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['binding_identity', 'update_token_index'], 'mp_la_token_unique');
            $table->unique(['binding_identity', 'client_activity_id'], 'mp_la_client_id_unique');
            $table->index(['scope', 'binding_identity', 'type_key', 'state'], 'mp_la_binding_type_state_idx');
            $table->index(['owner_type', 'owner_id', 'state'], 'mp_la_owner_state_idx');
            $table->index(['state', 'local_expires_at'], 'mp_la_state_expiry_idx');
            $table->index(['state', 'closed_at'], 'mp_la_state_closed_idx');
            $table->index('push_to_start_id', 'mp_la_push_to_start_idx');
            $table->index('broadcast_channel_id', 'mp_la_channel_idx');
            $table->index(['pending_due_at'], 'mp_la_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.live_activities.table', 'mobile_push_live_activities');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_live_activities';
    }
};
