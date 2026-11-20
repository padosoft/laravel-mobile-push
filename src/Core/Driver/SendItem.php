<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * One destination of a send, optionally resumed from a durable record: a relay passes the budget it persisted
 * (attempt count, deadline and the unknown-outcome flag are continued, never reset), its delivery id and the
 * provider message id used by the earlier attempts (so APNs sees the same apns-id again).
 */
final readonly class SendItem
{
    public function __construct(
        public Destination $destination,
        public ?RetryBudget $budget = null,
        public ?string $deliveryId = null,
        public ?string $providerMessageId = null,
    ) {
        if ($providerMessageId !== null && ! MessageIds::isCanonical($providerMessageId)) {
            throw new \InvalidArgumentException('A resumed provider message id must be a canonical lowercase UUID.');
        }
    }

    public static function of(Destination|self $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }
}
