<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

final readonly class PruneResult
{
    public function __construct(public int $deliveries = 0, public int $effects = 0) {}
}
