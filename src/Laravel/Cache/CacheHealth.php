<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Cache;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Reports a cache backend that misbehaves, at most once per minute per process.
 *
 * Only the operation name and the exception CLASS are logged: exception messages from cache clients can contain
 * connection strings and credentials, and the stores here handle bearer tokens.
 */
final class CacheHealth
{
    public const INTERVAL_SECONDS = 60;

    private ?int $lastReportAt = null;

    public function __construct(
        private readonly ?LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {}

    public function failed(string $operation, \Throwable $error): void
    {
        $this->warn($operation, $error::class);
    }

    public function unsupported(string $operation, string $reason): void
    {
        $this->warn($operation, $reason);
    }

    private function warn(string $operation, string $cause): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->lastReportAt !== null && $now - $this->lastReportAt < self::INTERVAL_SECONDS) {
            return;
        }
        $this->lastReportAt = $now;

        try {
            $this->logger?->warning('mobile-push cache unavailable: behaving as an empty store.', [
                'operation' => $operation,
                'cause' => $cause,
            ]);
        } catch (\Throwable) {
            // A failing logger must never break sending.
        }
    }
}
