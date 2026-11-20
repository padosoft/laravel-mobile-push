<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * APNs broadcast channels (iOS 18+). Published with: php artisan vendor:publish --tag=mobile-push-migrations-live-activities
 * Table and connection come from mobile-push.live_activities.channels_table / mobile-push.live_activities.connection.
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
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('scope', 64);
            $table->string('binding_name', 128);
            $table->char('binding_identity', 64);
            $table->string('app_id', 255);
            $table->string('environment', 16);

            $table->string('channel_id', 256)->nullable();       // as returned by APNs; null while creating / unknown
            $table->unsignedTinyInteger('storage_policy');        // 0 no storage, 1 most recent
            $table->string('label', 191)->nullable();
            $table->string('type_key', 191)->nullable();
            $table->string('state', 16);                          // creating | active | deleting | deleted | unknown
            $table->string('closed_reason', 32)->nullable();
            $table->string('create_request_id', 64)->nullable();  // apns-request-id of the create (for support)
            $table->dateTime('state_changed_at')->nullable();

            // Serialized publications.
            $table->json('last_content_state')->nullable();
            $table->string('last_event', 16)->nullable();
            $table->string('last_status', 32)->nullable();
            $table->unsignedTinyInteger('last_priority')->nullable();
            $table->unsignedBigInteger('last_timestamp')->nullable();
            $table->dateTime('last_published_at')->nullable();
            $table->unsignedInteger('publications')->default(0);  // accepted publications, never deliveries
            $table->dateTime('ended_at')->nullable();

            $table->unsignedInteger('dispatch_seq')->default(0);
            $table->unsignedInteger('applied_seq')->default(0);
            $table->unsignedInteger('pending_seq')->nullable();
            $table->json('pending_payload')->nullable();
            $table->unsignedBigInteger('pending_due_at')->nullable();
            $table->unsignedInteger('pending_attempts')->default(0);

            $table->unsignedInteger('version')->default(1);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['binding_identity', 'channel_id'], 'mp_bc_channel_unique');
            $table->index(['app_id', 'environment', 'state'], 'mp_bc_app_env_state_idx');
            $table->index(['scope', 'binding_identity', 'state'], 'mp_bc_binding_state_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.live_activities.channels_table', 'mobile_push_broadcast_channels');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_broadcast_channels';
    }
};
