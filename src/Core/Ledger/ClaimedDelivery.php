<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * A delivery handed to a worker: everything the relay needs for one Sender item.
 *
 * `$destination` is the decrypted typed destination for raw tokens, topics, conditions and channels. For a registry
 * device it is null: the ledger never copies registry tokens, resolve `$delivery->deviceRef` through the device
 * registry (that lookup is also the eligibility check right before the network).
 */
final readonly class ClaimedDelivery
{
    public function __construct(
        public Lease $lease,
        public Delivery $delivery,
        public ?Destination $destination,
    ) {}

    public function id(): string
    {
        return $this->delivery->id;
    }

    public function effectId(): string
    {
        return $this->delivery->effectId;
    }

    /** The budget to hand to the Sender (null before the first attempt). */
    public function budget(): ?RetryBudget
    {
        return $this->delivery->budget;
    }

    public function providerMessageId(): ?string
    {
        return $this->delivery->providerMessageId;
    }
}
