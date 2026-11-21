<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Device registry. Published with: php artisan vendor:publish --tag=mobile-push-migrations-devices
 * Table and connection come from mobile-push.devices.table / mobile-push.devices.connection.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mobile-push.devices.connection');

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
            $table->id();

            // Tenant and polymorphic owner (nullable: a device can be detached on logout).
            $table->string('scope', 64);
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 64)->nullable();

            // App binding, declared by the app at registration (never inferred from the token).
            $table->string('binding_name', 128);
            $table->char('binding_identity', 64);
            $table->string('provider', 16);
            $table->string('platform', 16)->nullable();
            $table->string('app_id', 255);
            $table->string('environment', 16);

            $table->string('kind', 32);
            $table->text('token');                          // encrypted envelope
            $table->string('token_index', 80);              // v<version>:<hmac>
            $table->text('fid')->nullable();                // encrypted FCM installation id
            $table->string('fid_index', 80)->nullable();

            $table->unsignedInteger('generation')->default(1);
            $table->string('state', 16)->default('active'); // active | quarantined | invalidated
            $table->string('state_reason', 64)->nullable();
            $table->dateTime('state_changed_at', 3)->nullable();
            $table->dateTime('registered_at', 3);
            $table->dateTime('last_seen_at', 3)->nullable();
            $table->dateTime('last_sent_at', 3)->nullable();

            $table->string('locale', 32)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->string('device_model', 128)->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['binding_identity', 'kind', 'token_index'], 'mp_devices_destination_unique');
            $table->index(['owner_type', 'owner_id', 'state'], 'mp_devices_owner_state_idx');
            $table->index(['state', 'state_changed_at'], 'mp_devices_state_changed_idx');
            $table->index('last_seen_at', 'mp_devices_last_seen_idx');
            $table->index('fid_index', 'mp_devices_fid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.devices.table', 'mobile_push_devices');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_devices';
    }
};
