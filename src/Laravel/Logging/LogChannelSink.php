<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** Writes one structured line per record to a Laravel log channel (any PSR-3 logger). */
final class LogChannelSink implements LogSink
{
    public const MESSAGE = 'mobile-push.send';

    public const DEFAULT_LEVELS = [
        'accepted' => LogLevel::DEBUG,
        'simulated' => LogLevel::DEBUG,
        'validated' => LogLevel::DEBUG,
        'deferred' => LogLevel::INFO,
        'skipped' => LogLevel::INFO,
        'expired' => LogLevel::WARNING,
        'rejected' => LogLevel::WARNING,
        'failed' => LogLevel::WARNING,
        'outcome_unknown' => LogLevel::WARNING,
    ];

    /** @var array<string, string> */
    private array $levels;

    /**
     * @param  array<string, string>  $levels  status value => PSR-3 level; missing statuses use the defaults
     */
    public function __construct(private readonly LoggerInterface $logger, array $levels = [])
    {
        $this->levels = [...self::DEFAULT_LEVELS, ...$levels];
    }

    public function levelFor(SendStatus $status): string
    {
        return $this->levels[$status->value] ?? LogLevel::WARNING;
    }

    public function write(LogRecord ...$records): void
    {
        foreach ($records as $record) {
            $this->logger->log($this->levelFor($record->status), self::MESSAGE, $record->toArray());
        }
    }

    public function flush(): void {}
}
