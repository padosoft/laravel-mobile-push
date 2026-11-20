<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness;

require_once dirname(__DIR__, 2).'/Ledger/LedgerContract.php';

/*
 * The contract again on the SKIP LOCKED claim path (the one PostgreSQL and MySQL 8 use). SQLite compiles the lock
 * clause to nothing, so this proves the code path behaves the same; it cannot prove row-lock skipping itself.
 */
ledgerContract(static fn (): LedgerHarness => DatabaseLedgerWorld::harness(strategy: ClaimStrategy::SkipLocked));
