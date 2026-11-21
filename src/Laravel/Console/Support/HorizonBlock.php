<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Laravel\Queue\QueueSettings;
use Padosoft\MobilePush\Laravel\Queue\QueueTimeouts;

/**
 * The Horizon supervisors and the plain `queue:work` commands that match the package's queue settings. The worker
 * timeout is the job timeout plus the margin the doctor demands, so `mobile-push:doctor` finds the chain consistent.
 */
final class HorizonBlock
{
    public const MEMORY_MB = 256;

    public static function workerTimeout(QueueSettings $settings): int
    {
        return (int) ceil($settings->timeout + QueueTimeouts::margin((float) $settings->timeout));
    }

    /** The smallest `retry_after` (or SQS visibility timeout) that is consistent with the worker timeout. */
    public static function minimumRetryAfter(QueueSettings $settings): int
    {
        $worker = (float) self::workerTimeout($settings);

        return (int) ceil($worker + QueueTimeouts::margin($worker));
    }

    public static function supervisors(QueueSettings $settings): string
    {
        $timeout = self::workerTimeout($settings);
        $memory = self::MEMORY_MB;

        return <<<PHP
// config/horizon.php, inside 'environments' => ['production' => [ ... ]]
'push' => [
    'connection' => 'redis',
    'queue' => ['{$settings->queue}'],
    'balance' => false,          // fixed, always-on processes for one-to-one pushes
    'processes' => 3,
    'tries' => 1,                // the package owns retries; the job never re-runs blindly
    'timeout' => {$timeout},
    'memory' => {$memory},
],
'push-bulk' => [
    'connection' => 'redis',
    'queue' => ['{$settings->bulkQueue}'],
    'balance' => 'auto',         // scales with the backlog of campaign / bulk traffic
    'minProcesses' => 1,
    'maxProcesses' => 8,
    'balanceMaxShift' => 1,
    'balanceCooldown' => 3,
    'tries' => 1,
    'timeout' => {$timeout},
    'memory' => {$memory},
],
PHP;
    }

    /** @return list<string> */
    public static function queueWork(QueueSettings $settings, string $queueConnection): array
    {
        $timeout = self::workerTimeout($settings);
        $memory = self::MEMORY_MB;

        return [
            sprintf('php artisan queue:work %s --queue=%s --tries=1 --timeout=%d --memory=%d', $queueConnection, $settings->queue, $timeout, $memory),
            sprintf('php artisan queue:work %s --queue=%s --tries=1 --timeout=%d --memory=%d', $queueConnection, $settings->bulkQueue, $timeout, $memory),
        ];
    }
}
