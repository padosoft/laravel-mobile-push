<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts\Events;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

/**
 * The app reported that it opened (tapped) the notification of this delivery. A client-reported fact, not proof that a person read it. Dispatched once
 * per delivery. Carries no token and no marker.
 */
final readonly class PushOpenedReported
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
