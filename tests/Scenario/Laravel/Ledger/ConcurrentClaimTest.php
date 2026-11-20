<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * "Two workers, one queue", with a real concurrent writer: two connections to one SQLite file. Every time worker A
 * is about to lease a candidate it already selected, worker B (its own connection, its own ledger instance) claims
 * first. A's guarded UPDATE must then lose on every row B took.
 */
beforeEach(function (): void {
    $this->file = tempnam(sys_get_temp_dir(), 'mp-ledger-');
    foreach (['ledger_a', 'ledger_b'] as $name) {
        config()->set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => false]);
    }
});

afterEach(function (): void {
    DB::purge('ledger_a');
    DB::purge('ledger_b');
    @unlink($this->file);
});

it('every delivery is claimed exactly once even when the other worker wins between select and update', function (ClaimStrategy $strategy): void {
    $clock = new FakeClock;
    $a = DatabaseLedgerWorld::harness('ledger_a', $strategy, $clock);
    $b = DatabaseLedgerWorld::harness('ledger_b', $strategy, $clock, migrate: false);
    $a->ledger->recordIntent(H::intent(H::alert()), H::tokens(1000));

    $byB = [];
    $interleavings = 0;
    $inside = false;
    DB::connection('ledger_a')->beforeExecuting(function (string $sql) use (&$byB, &$interleavings, &$inside, $b): void {
        if ($inside || ! str_starts_with(strtolower(ltrim($sql)), 'update') || ! str_contains($sql, 'lease_owner')) {
            return;
        }
        $inside = true;
        $interleavings++;
        foreach ($b->claimIds('default', 'worker-b', 3) as $id) {
            $byB[] = $id;
        }
        $inside = false;
    });

    $byA = [];
    do {
        $got = $a->claimIds('default', 'worker-a', 25);
        array_push($byA, ...$got);
    } while ($got !== [] || DB::connection('ledger_a')->table('mobile_push_deliveries')->where('state', 'pending')->exists());

    $all = array_merge($byA, $byB);
    expect($interleavings)->toBeGreaterThan(0)
        ->and($byB)->not->toBeEmpty()
        ->and(array_intersect($byA, $byB))->toBe([])
        ->and($all)->toHaveCount(1000)
        ->and(array_unique($all))->toHaveCount(1000)
        ->and(DB::connection('ledger_a')->table('mobile_push_deliveries')->where('lease_owner', 'worker-a')->count())->toBe(count($byA))
        ->and(DB::connection('ledger_a')->table('mobile_push_deliveries')->where('lease_owner', 'worker-b')->count())->toBe(count($byB));
})->with([
    // The SKIP LOCKED path cannot be interleaved this way on SQLite: its short transaction holds the single
    // database write lock, so the other connection waits (that waiting is itself what keeps it exclusive).
    'optimistic (SQLite, any database)' => [ClaimStrategy::Optimistic],
]);

it('a stale lease loses to a concurrent reclaim + claim on the other connection', function (): void {
    $clock = new FakeClock;
    $a = DatabaseLedgerWorld::harness('ledger_a', ClaimStrategy::Optimistic, $clock);
    $b = DatabaseLedgerWorld::harness('ledger_b', ClaimStrategy::Optimistic, $clock, migrate: false);
    $a->ledger->recordIntent(H::intent(H::liveUpdate()), [H::token(1)]);

    $claimA = $a->ledger->claimDue('default', 'worker-a', 1)[0];
    $a->ledger->markAttemptStarted($claimA->lease);
    $a->expireLeases();
    $claimB = $b->ledger->claimDue('default', 'worker-b', 1)[0];

    expect($a->ledger->record($claimA->lease, $a->accepted($claimA, 'from-a'))->isIgnored())->toBeTrue()
        ->and($b->ledger->markAttemptStarted($claimB->lease))->toBeTrue()
        ->and($b->ledger->record($claimB->lease, $b->accepted($claimB, 'from-b'))->isRecorded())->toBeTrue()
        ->and($a->ledger->delivery('default', $claimB->id())->providerMessageId)->toBe('from-b');
});
