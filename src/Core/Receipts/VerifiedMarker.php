<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Receipts;

/** What an authentic, unexpired marker proves: the scope and delivery reference it was issued for. Never holds the marker. */
final readonly class VerifiedMarker
{
    public function __construct(
        public string $scope,
        /** The sender-generated message id (canonical lowercase UUID) of the delivery. */
        public string $deliveryReference,
        public \DateTimeImmutable $expiresAt,
        public int $keyVersion,
    ) {}
}
