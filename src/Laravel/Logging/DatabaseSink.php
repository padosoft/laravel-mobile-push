<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Illuminate\Database\ConnectionResolverInterface;

/**
 * Persists records with bulk inserts (one statement per chunk) in the table and connection from
 * mobile-push.log.database.*. created_at is stored in UTC.
 *
 * Mode: `all`; `failures` (everything except routine successes: accepted, simulated, validated);
 * `sampled` (non-routine outcomes always, routine successes with probability $sampleRate).
 */
final class DatabaseSink implements LogSink, ReportsFiltered
{
    public const DATE_FORMAT = 'Y-m-d H:i:s.v';

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private int $filtered = 0;

    /** @var \Closure(): int returns an integer in [0, 1_000_000) */
    private \Closure $random;

    /**
     * @param  \Closure(): int|null  $random  test seam for the sampler
     */
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly string $table = 'mobile_push_logs',
        private readonly ?string $connection = null,
        private readonly DatabaseMode $mode = DatabaseMode::Failures,
        private readonly float $sampleRate = 0.01,
        private readonly int $insertChunk = 100,
        ?\Closure $random = null,
    ) {
        $this->random = $random ?? static fn (): int => random_int(0, 999_999);
    }

    /** Whether a record would be persisted under this mode. */
    public function accepts(LogRecord $record): bool
    {
        return match ($this->mode) {
            DatabaseMode::All => true,
            DatabaseMode::Failures => ! $record->isRoutine(),
            DatabaseMode::Sampled => ! $record->isRoutine() || self::sample($this->sampleRate, ($this->random)()),
        };
    }

    /** Sampling arithmetic: keep when the draw (0..999_999) falls under rate * 1_000_000. */
    public static function sample(float $rate, int $draw): bool
    {
        return $draw < (int) round(max(0.0, min(1.0, $rate)) * 1_000_000);
    }

    public function write(LogRecord ...$records): void
    {
        foreach ($records as $record) {
            if ($this->accepts($record)) {
                $this->rows[] = self::row($record);
            } else {
                $this->filtered++;
            }
        }
    }

    public function flush(): void
    {
        $rows = $this->rows;
        $this->rows = [];

        if ($rows === []) {
            return;
        }

        $connection = $this->db->connection($this->connection);
        foreach (array_chunk($rows, max(1, $this->insertChunk)) as $chunk) {
            $connection->table($this->table)->insert($chunk);
        }
    }

    public function filtered(): int
    {
        return $this->filtered;
    }

    /** @return array<string, mixed> */
    public static function row(LogRecord $r): array
    {
        $payload = $r->payload === null ? null : json_encode($r->payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return [
            'created_at' => $r->at->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT),
            'scope' => $r->scope,
            'provider' => $r->provider,
            'binding_name' => $r->bindingName,
            'app_id' => $r->appId,
            'environment' => $r->environment,
            'operation' => $r->operation,
            'push_type' => $r->pushType,
            'traffic_class' => $r->trafficClass,
            'destination_kind' => $r->destinationKind,
            'destination_index' => $r->destinationIndex,
            'destination_masked' => $r->destinationMasked,
            'status' => $r->status->value,
            'reason_code' => $r->reasonCode->value,
            'reason_raw' => $r->reason,
            'http_status' => $r->httpStatus,
            'provider_message_id' => $r->providerMessageId,
            'attempts' => $r->attempts,
            'duration_ms' => $r->durationMs,
            'payload_bytes' => $r->payloadBytes,
            'payload_level' => $r->payloadLevel->value,
            'payload' => $payload === false ? null : $payload,
            'effect_id' => $r->effectId,
            'delivery_id' => $r->deliveryId,
            'campaign_id' => $r->campaignId,
            'origin_type' => $r->originType,
            'origin_id' => $r->originId,
            'notification' => $r->notification,
        ];
    }
}
