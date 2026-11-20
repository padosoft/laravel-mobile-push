<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

final readonly class PruneReport
{
    public function __construct(
        public int $invalidated,
        public int $inactive,
        public bool $dryRun,
    ) {}

    public function total(): int
    {
        return $this->invalidated + $this->inactive;
    }
}
