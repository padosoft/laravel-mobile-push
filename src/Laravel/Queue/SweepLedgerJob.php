<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Scheduled every minute in the durable profile: runs LedgerSweeper::sweep(). Idempotent; carries nothing. */
final class SweepLedgerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    public function handle(LedgerSweeper $sweeper): void
    {
        $sweeper->sweep();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['mobile-push', 'sweep'];
    }

    public function displayName(): string
    {
        return 'mobile-push ledger sweep';
    }
}
