<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * Result of applying one event to one state. An ignored event leaves the row untouched (it is not an error:
 * late, duplicate or stale writes are expected in a distributed relay).
 */
final readonly class Transition
{
    private function __construct(
        public bool $allowed,
        public DeliveryState $from,
        public DeliveryState $to,
        /** The row is in flight and cannot be cancelled now: remember the request so no further attempt is made. */
        public bool $requestsCancel = false,
    ) {}

    public static function to(DeliveryState $from, DeliveryState $to, bool $requestsCancel = false): self
    {
        return new self(true, $from, $to, $requestsCancel);
    }

    public static function ignored(DeliveryState $from): self
    {
        return new self(false, $from, $from);
    }
}
