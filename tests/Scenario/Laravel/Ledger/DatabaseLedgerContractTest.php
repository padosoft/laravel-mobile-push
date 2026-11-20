<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness;

require_once dirname(__DIR__, 2).'/Ledger/LedgerContract.php';

/*
 * The same ledger contract against the database implementation (SQLite in memory here; the optimistic claim
 * path is what SQLite resolves to). LedgerSkipLockedPathTest re-runs the claim stories on the other code path.
 */
ledgerContract(static fn (): LedgerHarness => DatabaseLedgerWorld::harness(strategy: ClaimStrategy::Optimistic));
