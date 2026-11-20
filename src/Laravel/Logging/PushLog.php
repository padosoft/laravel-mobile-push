<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model of the send log table (written by DatabaseSink with bulk inserts, never through this model).
 * Table and connection come from mobile-push.log.database.table / .connection. created_at is UTC.
 *
 * @property int $id
 * @property string $created_at
 * @property string $status
 * @property string $reason_code
 * @property string $destination_index
 * @property string|null $campaign_id
 */
final class PushLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'http_status' => 'integer',
        'attempts' => 'integer',
        'duration_ms' => 'integer',
        'payload_bytes' => 'integer',
        'payload' => 'array',
    ];

    public function getTable(): string
    {
        $table = config('mobile-push.log.database.table', 'mobile_push_logs');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_logs';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('mobile-push.log.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
