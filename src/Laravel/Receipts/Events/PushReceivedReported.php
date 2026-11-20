<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts\Events;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

/**
 * The app reported that it received this delivery. A client-reported fact, not proof of delivery. Dispatched once
 * per delivery. Carries no token and no marker.
 */
final readonly class PushReceivedReported
{
    public function __construct(
        public string $scope,
        public string $effectId,
        public string $deliveryId,
        public DestinationKind $destinationKind,
        public \DateTimeImmutable $serverTime,
        public \DateTimeImmutable $clientTime,
    ) {}
}
