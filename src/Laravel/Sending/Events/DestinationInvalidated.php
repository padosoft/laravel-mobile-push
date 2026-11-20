<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/**
 * The provider said the registration is gone (APNs 410 Unregistered, FCM UNREGISTERED). `$registryChange` is
 * what the device registry did ("invalidated", "stale_timestamp", "generation_changed", ...), null when the
 * registry is not enabled or the destination has no registry reference.
 */
final readonly class DestinationInvalidated
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
