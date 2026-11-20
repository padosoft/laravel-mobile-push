<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Contracts;

/**
 * Where and when a queued send runs. "connection" here is the QUEUE connection, never the push connection.
 */
final readonly class QueueOptions
{
    public function __construct(
        public ?string $queueConnection = null,
        public ?string $queue = null,
        public \DateTimeInterface|int|null $delay = null,
        public bool $afterCommit = true,
    ) {}
}
