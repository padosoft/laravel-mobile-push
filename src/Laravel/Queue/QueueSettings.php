<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Config\Repository;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;

/**
 * The queue settings of the package, read once from config with explicit defaults.
 *
 * Keys (all under `mobile-push.`): delivery.profile, queue.connection, queue.queue, queue.bulk_queue, queue.chunk,
 * queue.chunk_bytes, queue.timeout, queue.chunk_deadline, queue.after_commit, queue.follow_up_bucket,
 * queue.worker_timeout, queue.visibility_timeout, queue.sweeper.schedule, queue.sweeper.scopes_per_run,
 * ledger.lease_seconds, transport.timeout_ms.
 */
final readonly class QueueSettings
{
    public const PROFILE_LIGHT = 'light';

    public const PROFILE_DURABLE = 'durable';

    public const MAX_CHUNK = 10_000;

    public const MIN_CHUNK_BYTES = 4096;

    public function __construct(
        /** light | durable */
        public string $profile = self::PROFILE_LIGHT,
        /** The QUEUE connection (null = the application's default queue connection). */
        public ?string $connection = null,
        public string $queue = 'push',
        public string $bulkQueue = 'push-bulk',
        /** Destinations per light job / deliveries claimed per relay chunk. */
        public int $chunk = 500,
        /** Estimated serialized bytes of one light job before encryption (the encrypted payload is about 1.8x). */
        public int $chunkBytes = 98_304,
        /** Job timeout in seconds (the worker kills a job that runs longer). */
        public int $timeout = 120,
        /** Wall time in seconds one relay run spends claiming and sending before it stops and hands over. */
        public int $chunkDeadline = 60,
        public bool $afterCommit = true,
        /** Light profile: deferred destinations whose next attempts are this close share one continuation job. */
        public int $followUpBucket = 60,
        /** Declared worker / Horizon timeout, used when it cannot be read from the Horizon config. */
        public ?int $workerTimeout = null,
        /** Declared SQS visibility timeout (it is not in the Laravel config). */
        public ?int $visibilityTimeout = null,
        public bool $scheduleSweeper = true,
        public int $sweepScopes = 100,
        /** ledger.lease_seconds: must exceed the chunk deadline. */
        public int $leaseSeconds = 120,
        /** The longest transport request timeout in milliseconds. */
        public int $transportTimeoutMs = 30_000,
    ) {
        if (! in_array($profile, [self::PROFILE_LIGHT, self::PROFILE_DURABLE], true)) {
            throw new ConfigurationException('mobile-push.delivery.profile must be one of: light, durable.');
        }
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new ConfigurationException('mobile-push.queue.chunk must be between 1 and '.self::MAX_CHUNK.'.');
        }
        if ($chunkBytes < self::MIN_CHUNK_BYTES) {
            throw new ConfigurationException('mobile-push.queue.chunk_bytes must be at least '.self::MIN_CHUNK_BYTES.'.');
        }
        if ($timeout < 1 || $chunkDeadline < 1 || $followUpBucket < 0 || $sweepScopes < 1 || $leaseSeconds < 1 || $transportTimeoutMs < 1) {
            throw new ConfigurationException('mobile-push.queue timeouts, deadlines and limits must be positive.');
        }
        if ($queue === '' || $bulkQueue === '') {
            throw new ConfigurationException('mobile-push.queue.queue and queue.bulk_queue cannot be empty.');
        }
    }

    public static function fromConfig(Repository $config): self
    {
        $transport = self::int($config, 'mobile-push.transport.timeout_ms', 30_000);
        $connections = $config->get('mobile-push.connections', []);
        if (is_array($connections)) {
            foreach ($connections as $connection) {
                if (is_array($connection) && is_numeric($connection['timeout_ms'] ?? null)) {
                    $transport = max($transport, (int) $connection['timeout_ms']);
                }
            }
        }

        return new self(
            profile: self::string($config, 'mobile-push.delivery.profile') ?? self::PROFILE_LIGHT,
            connection: self::string($config, 'mobile-push.queue.connection'),
            queue: self::string($config, 'mobile-push.queue.queue') ?? 'push',
            bulkQueue: self::string($config, 'mobile-push.queue.bulk_queue') ?? 'push-bulk',
            chunk: self::int($config, 'mobile-push.queue.chunk', 500),
            chunkBytes: self::int($config, 'mobile-push.queue.chunk_bytes', 98_304),
            timeout: self::int($config, 'mobile-push.queue.timeout', 120),
            chunkDeadline: self::int($config, 'mobile-push.queue.chunk_deadline', 60),
            afterCommit: self::bool($config, 'mobile-push.queue.after_commit', true),
            followUpBucket: self::int($config, 'mobile-push.queue.follow_up_bucket', 60),
            workerTimeout: self::intOrNull($config, 'mobile-push.queue.worker_timeout'),
            visibilityTimeout: self::intOrNull($config, 'mobile-push.queue.visibility_timeout'),
            scheduleSweeper: self::bool($config, 'mobile-push.queue.sweeper.schedule', true),
            sweepScopes: self::int($config, 'mobile-push.queue.sweeper.scopes_per_run', 100),
            leaseSeconds: self::int($config, 'mobile-push.ledger.lease_seconds', 120),
            transportTimeoutMs: $transport,
        );
    }

    public function durable(): bool
    {
        return $this->profile === self::PROFILE_DURABLE;
    }

    /** The queue name for a traffic class when the caller did not name one. */
    public function queueFor(TrafficClass $trafficClass): string
    {
        return $trafficClass === TrafficClass::Bulk ? $this->bulkQueue : $this->queue;
    }

    private static function string(Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function intOrNull(Repository $config, string $key): ?int
    {
        $value = $config->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function bool(Repository $config, string $key, bool $default): bool
    {
        $value = $config->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
