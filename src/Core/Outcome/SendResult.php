<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

use Padosoft\MobilePush\Core\Identity\Destination;

/**
 * The current outcome of one destination for one effect.
 */
final readonly class SendResult
{
    public function __construct(
        public Destination $destination,
        public SendStatus $status,
        public Reason $reason,
        public int $attempts = 0,
        public ?string $providerMessageId = null,
        public ?int $httpStatus = null,
        public ?\DateTimeImmutable $nextAttemptAt = null,
        public DestinationAction $destinationAction = DestinationAction::None,
        public ?int $invalidatedAtMs = null,
        /** Ledger delivery id (durable profile only). */
        public ?string $deliveryId = null,
        /** @var array<string, string> */
        public array $meta = [],
    ) {}

    public function isAccepted(): bool
    {
        return $this->status->isAccepted();
    }
}
