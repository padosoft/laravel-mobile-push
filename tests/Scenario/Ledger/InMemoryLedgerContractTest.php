<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness;

require_once __DIR__.'/LedgerContract.php';

/*
 * The ledger contract against the in-memory reference implementation (no framework).
 */
ledgerContract(static fn (): LedgerHarness => LedgerHarness::inMemory());
