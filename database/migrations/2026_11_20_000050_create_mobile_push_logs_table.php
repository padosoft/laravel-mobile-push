<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Send log (database sink). Published with: php artisan vendor:publish --tag=mobile-push-migrations-logs
 * Table and connection come from mobile-push.log.database.table / mobile-push.log.database.connection.
 * No device token, bearer or receipt marker is ever stored: destinations are a keyed index plus a masked form.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.log.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->dateTime('created_at', 3);              // UTC

            $table->string('scope', 64);
            $table->string('provider', 16);
            $table->string('binding_name', 128);
            $table->string('app_id', 255);
            $table->string('environment', 16);
            $table->string('operation', 32);
            $table->string('push_type', 32)->nullable();
            $table->string('traffic_class', 32)->nullable();

            $table->string('destination_kind', 32);
            $table->string('destination_index', 80);        // v<version>:<hmac>
            $table->string('destination_masked', 32);

            $table->string('status', 24);
            $table->string('reason_code', 48);
            $table->string('reason_raw', 191)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('payload_bytes')->nullable();
            $table->string('payload_level', 8)->default('none');
            $table->text('payload')->nullable();            // JSON: key names or redacted payload, per payload level

            $table->string('effect_id', 64)->nullable();
            $table->string('delivery_id', 64)->nullable();
            $table->string('campaign_id', 64)->nullable();
            $table->string('origin_type', 191)->nullable();
            $table->string('origin_id', 64)->nullable();
            $table->string('notification', 191)->nullable();

            $table->index('created_at');
            $table->index(['destination_index', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.log.database.table', 'mobile_push_logs');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_logs';
    }
};
