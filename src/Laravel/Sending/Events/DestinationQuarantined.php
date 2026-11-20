<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/**
 * The provider said the destination is wrong for this app or environment (for example APNs BadDeviceToken): the
 * registration is suspended, never deleted. `$registryChange` is what the device registry did (a DeviceChange
 * value such as "quarantined" or "generation_changed"), null when the registry is not enabled or the destination
 * has no registry reference.
 */
final readonly class DestinationQuarantined
{
    public function __construct(
        public BindingRef $binding,
        public DestinationView $destination,
        public ReasonCode $reason,
        public ?string $rawReason,
        public ?string $registryChange,
        public ?int $invalidatedAtMs = null,
    ) {}
}
