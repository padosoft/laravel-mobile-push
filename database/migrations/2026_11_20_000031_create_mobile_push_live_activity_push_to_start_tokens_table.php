<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Live Activity push-to-start tokens (iOS 17.2+), one per device, app binding and Swift attributes type.
 * Published with: php artisan vendor:publish --tag=mobile-push-migrations-live-activities
 * Table and connection come from mobile-push.live_activities.push_to_start_table / mobile-push.live_activities.connection.
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
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->string('type_key', 191);

            $table->text('token');                         // encrypted envelope
            $table->string('token_index', 80);             // v<version>:<hmac>
            $table->unsignedInteger('generation')->default(1);
            $table->string('state', 16)->default('active'); // active | quarantined | invalidated
            $table->string('state_reason', 64)->nullable();
            $table->dateTime('state_changed_at')->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('last_seen_at')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['binding_identity', 'type_key', 'token_index'], 'mp_la_p2s_token_unique');
            $table->index(['owner_type', 'owner_id', 'state'], 'mp_la_p2s_owner_state_idx');
            $table->index(['state', 'state_changed_at'], 'mp_la_p2s_state_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.live_activities.push_to_start_table', 'mobile_push_live_activity_push_to_start_tokens');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_live_activity_push_to_start_tokens';
    }
};
