<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * Proof that a worker holds a delivery. Every write a worker makes is guarded by (id, scope, owner, version): once
 * anyone else changes the row (reclaim, cancel, another worker) the version moves on and this lease is stale.
 */
final readonly class Lease
{
    public function __construct(
        public string $deliveryId,
        public string $scope,
        public string $owner,
        public int $version,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
