<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Durable ledger: effects (one logical thing to send). Durable profile only.
 * Published with: php artisan vendor:publish --tag=mobile-push-migrations-ledger
 * Table and connection: mobile-push.ledger.tables.effects / mobile-push.ledger.connection.
 * Times are UTC epoch milliseconds (BIGINT): exact and identical on SQLite, MySQL and PostgreSQL.
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
            $table->char('id', 26)->primary();                    // ULID, generated before any dispatch
            $table->string('scope', 64);
            $table->text('binding');                              // BindingRef::toArray() as JSON (no secret)
            $table->char('binding_identity', 64);
            $table->string('effect_key', 191);
            $table->char('dedupe_key', 64)->nullable();           // sha256(effect_key); NULL once the dedupe window passed
            $table->string('fingerprint', 80);
            $table->longText('message')->nullable();              // encrypted snapshot; NULL after forget
            $table->string('operation', 32);
            $table->boolean('replaceable')->default(false);
            $table->unsignedBigInteger('expires_at')->nullable(); // logical expiry
            $table->string('policy', 64)->nullable();
            $table->string('traffic_class', 16);
            $table->string('origin_type', 191)->nullable();
            $table->string('origin_id', 64)->nullable();
            $table->string('notification_class', 191)->nullable();
            $table->string('campaign_id', 64)->nullable();
            $table->unsignedBigInteger('dedupe_until');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');

            $table->unique(['scope', 'binding_identity', 'dedupe_key'], 'mp_effects_key_unique');
            $table->index(['scope', 'campaign_id'], 'mp_effects_campaign_idx');
            $table->index(['scope', 'origin_type', 'origin_id'], 'mp_effects_origin_idx');
            $table->index('dedupe_until', 'mp_effects_dedupe_until_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('mobile-push.ledger.tables.effects', 'mobile_push_effects');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_effects';
    }
};
