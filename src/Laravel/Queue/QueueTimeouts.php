<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Config\Repository;

/**
 * The time budget chain of plan §6.6, computed and validated from config:
 *
 *   transport timeout < chunk deadline < job timeout < worker (Horizon) timeout < retry_after (SQS: visibility timeout)
 *   and, in the durable profile, chunk deadline < ledger lease
 *
 * each with a margin of max(5 s, 10% of the lower value). A value that cannot be read from this process (the
 * `queue:work --timeout` flag, an SQS queue attribute, an unknown driver) makes its links "not verifiable", never
 * "ok"; they can be declared with mobile-push.queue.worker_timeout and mobile-push.queue.visibility_timeout.
 * The sync driver runs jobs in-process with no worker and no redelivery: those links are "not applicable".
 */
final class QueueTimeouts
{
    public const MIN_MARGIN_SECONDS = 5.0;

    public const MARGIN_RATIO = 0.1;

    /** Laravel's own retry_after when a database / redis / beanstalkd connection does not set one. */
    public const FRAMEWORK_RETRY_AFTER = 60;

    /** Horizon's supervisor timeout when a supervisor does not set one. */
    public const HORIZON_DEFAULT_TIMEOUT = 60;

    private const KEYS = [
        'transport_timeout' => 'mobile-push.transport.timeout_ms (or a connection\'s timeout_ms)',
        'chunk_deadline' => 'mobile-push.queue.chunk_deadline',
        'job_timeout' => 'mobile-push.queue.timeout',
        'worker_timeout' => 'the Horizon supervisor "timeout" or `queue:work --timeout` (declare it in mobile-push.queue.worker_timeout)',
        'retry_after' => 'queue.connections.<connection>.retry_after (SQS: the queue visibility timeout, declared in mobile-push.queue.visibility_timeout)',
        'lease_seconds' => 'mobile-push.ledger.lease_seconds',
    ];

    /**
     * Pure evaluation. Seconds everywhere; null = unknown. `$workerTimeoutMax` is the largest timeout among several
     * matching supervisors (the smallest is `$workerTimeout`).
     *
     * @param  array<string, string>  $sources
     * @param  bool  $inProcess  sync driver: no worker, no redelivery
     */
    public static function evaluate(
        float $transportTimeout,
        float $chunkDeadline,
        float $jobTimeout,
        ?float $workerTimeout,
        ?float $retryAfter,
        ?float $leaseSeconds = null,
        bool $inProcess = false,
        ?float $workerTimeoutMax = null,
        array $sources = [],
    ): TimeoutChain {
        $workerTimeoutMax ??= $workerTimeout;
        $links = [
            self::link('transport_timeout', 'chunk_deadline', $transportTimeout, $chunkDeadline),
            self::link('chunk_deadline', 'job_timeout', $chunkDeadline, $jobTimeout),
        ];

        if ($inProcess) {
            $links[] = self::notApplicable('job_timeout', 'worker_timeout', $jobTimeout);
            $links[] = self::notApplicable('worker_timeout', 'retry_after', null);
        } else {
            $links[] = self::link('job_timeout', 'worker_timeout', $jobTimeout, $workerTimeout);
            $links[] = self::link('worker_timeout', 'retry_after', $workerTimeoutMax, $retryAfter);
            if ($workerTimeout === null) {
                // Laravel's own rule still applies when the worker cannot be read.
                $links[] = self::link('job_timeout', 'retry_after', $jobTimeout, $retryAfter);
            }
        }

        if ($leaseSeconds !== null) {
            $links[] = self::link('chunk_deadline', 'lease_seconds', $chunkDeadline, $leaseSeconds);
        }

        return new TimeoutChain([
            'transport_timeout' => $transportTimeout,
            'chunk_deadline' => $chunkDeadline,
            'job_timeout' => $jobTimeout,
            'worker_timeout' => $inProcess ? null : $workerTimeout,
            'worker_timeout_max' => $inProcess ? null : $workerTimeoutMax,
            'retry_after' => $inProcess ? null : $retryAfter,
            'lease_seconds' => $leaseSeconds,
        ], $links, $sources);
    }

    /** The chain for the queue connection mobile-push uses (or the one given), read from the current config. */
    public static function fromConfig(Repository $config, ?QueueSettings $settings = null, ?string $queueConnection = null): TimeoutChain
    {
        $settings ??= QueueSettings::fromConfig($config);
        $default = $config->get('queue.default');
        $name = $queueConnection ?? $settings->connection ?? (is_string($default) ? $default : null);
        $connection = $name === null ? null : $config->get('queue.connections.'.$name);
        $driver = is_array($connection) && is_string($connection['driver'] ?? null) ? $connection['driver'] : null;
        $sources = ['queue_connection' => $name ?? 'none', 'driver' => $driver ?? 'unknown'];

        [$retryAfter, $sources['retry_after']] = match (true) {
            $driver === null => [null, 'queue connection not found'],
            in_array($driver, ['database', 'redis', 'beanstalkd'], true) => is_numeric($connection['retry_after'] ?? null)
                ? [(float) $connection['retry_after'], 'queue.connections.'.$name.'.retry_after']
                : [(float) self::FRAMEWORK_RETRY_AFTER, 'framework default'],
            $driver === 'sqs' => $settings->visibilityTimeout !== null
                ? [(float) $settings->visibilityTimeout, 'mobile-push.queue.visibility_timeout (declared)']
                : [null, 'SQS visibility timeout is an attribute of the queue, not in the Laravel config'],
            default => [null, 'driver "'.$driver.'" cannot be inspected'],
        };

        [$workerMin, $workerMax, $sources['worker_timeout']] = self::workerTimeout($config, $settings, $name);

        return self::evaluate(
            $settings->transportTimeoutMs / 1000,
            (float) $settings->chunkDeadline,
            (float) $settings->timeout,
            $workerMin,
            $retryAfter,
            $settings->durable() ? (float) $settings->leaseSeconds : null,
            $driver === 'sync',
            $workerMax,
            $sources,
        );
    }

    /** max(5 s, 10% of the lower value). */
    public static function margin(float $lower): float
    {
        return max(self::MIN_MARGIN_SECONDS, $lower * self::MARGIN_RATIO);
    }

    /**
     * Horizon supervisors that work our queues on our connection (defaults merged with the current environment),
     * else the declared worker timeout, else unknown.
     *
     * @return array{0: ?float, 1: ?float, 2: string}
     */
    private static function workerTimeout(Repository $config, QueueSettings $settings, ?string $connection): array
    {
        $env = $config->get('app.env');
        $defaults = $config->get('horizon.defaults', []);
        $environments = $config->get('horizon.environments', []);
        $supervisors = is_array($environments) && is_string($env) && is_array($environments[$env] ?? null) ? $environments[$env] : [];

        $timeouts = [];
        foreach ($supervisors as $supervisor => $options) {
            $merged = array_replace(is_array($defaults) && is_array($defaults[$supervisor] ?? null) ? $defaults[$supervisor] : [], is_array($options) ? $options : []);
            $queues = $merged['queue'] ?? ['default'];
            $queues = is_array($queues) ? array_filter($queues, is_string(...)) : explode(',', is_string($queues) ? $queues : '');
            $on = is_string($merged['connection'] ?? null) ? $merged['connection'] : 'redis';
            if ($on !== $connection || array_intersect([$settings->queue, $settings->bulkQueue], $queues) === []) {
                continue;
            }
            $timeouts[] = is_numeric($merged['timeout'] ?? null) ? (float) $merged['timeout'] : (float) self::HORIZON_DEFAULT_TIMEOUT;
        }

        if ($timeouts !== []) {
            return [min($timeouts), max($timeouts), 'horizon supervisors'];
        }
        if ($settings->workerTimeout !== null) {
            return [(float) $settings->workerTimeout, (float) $settings->workerTimeout, 'mobile-push.queue.worker_timeout (declared)'];
        }

        return [null, null, 'not inspectable (queue:work --timeout); declare mobile-push.queue.worker_timeout'];
    }

    private static function link(string $lower, string $upper, ?float $lowerValue, ?float $upperValue): TimeoutLink
    {
        $margin = $lowerValue === null ? self::MIN_MARGIN_SECONDS : self::margin($lowerValue);
        if ($lowerValue === null || $upperValue === null) {
            $unknown = $lowerValue === null ? $lower : $upper;

            return new TimeoutLink($lower, $upper, $lowerValue, $upperValue, $margin, ChainStatus::NotVerifiable,
                sprintf('%s cannot be read here: check it by hand or declare it (%s).', $unknown, self::KEYS[$unknown]));
        }
        if ($upperValue >= $lowerValue + $margin) {
            return new TimeoutLink($lower, $upper, $lowerValue, $upperValue, $margin, ChainStatus::Ok);
        }

        return new TimeoutLink($lower, $upper, $lowerValue, $upperValue, $margin, ChainStatus::Violation, sprintf(
            'Raise %s to at least %s s (%s), or lower %s to at most %s s (%s).',
            $upper, self::seconds(ceil(($lowerValue + $margin) * 1000) / 1000), self::KEYS[$upper],
            $lower, self::seconds(self::largestLowerFor($upperValue)), self::KEYS[$lower],
        ));
    }

    private static function notApplicable(string $lower, string $upper, ?float $lowerValue): TimeoutLink
    {
        return new TimeoutLink($lower, $upper, $lowerValue, null, 0.0, ChainStatus::NotApplicable,
            'The sync driver runs the job in-process: there is no worker timeout and no redelivery.');
    }

    /** The largest lower value whose margin still fits under `$upper`. */
    private static function largestLowerFor(float $upper): float
    {
        return max(0.0, min($upper - self::MIN_MARGIN_SECONDS, $upper / (1 + self::MARGIN_RATIO)));
    }

    private static function seconds(float $value): string
    {
        $value = floor($value * 1000) / 1000;

        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
