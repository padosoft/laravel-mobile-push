<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * Two processes writing the same ledger, interleaved deterministically: worker A runs on connection `ledger_a`; a
 * hook on that connection lets another writer (worker B, a canceller, the sweeper) act right before A's statement
 * executes, i.e. between what A read and what A writes. Same technique as ConcurrentClaimTest: two connections to
 * one SQLite file, or to the server database in the MySQL / PostgreSQL jobs.
 */
beforeEach(function (): void {
    $default = (string) config('database.default');
    $this->onServer = in_array(config("database.connections.{$default}.driver"), ['mysql', 'mariadb', 'pgsql'], true);
    $this->file = $this->onServer ? null : tempnam(sys_get_temp_dir(), 'mp-ledger-');
    foreach (['ledger_a', 'ledger_b'] as $name) {
        config()->set("database.connections.{$name}", $this->onServer
            ? config("database.connections.{$default}")
            : ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => false]);
    }
    $this->clock = new FakeClock;
    $this->a = DatabaseLedgerWorld::harness('ledger_a', ClaimStrategy::Optimistic, $this->clock);
    $this->b = DatabaseLedgerWorld::harness('ledger_b', ClaimStrategy::Optimistic, $this->clock, migrate: false);
});

afterEach(function (): void {
    DB::purge('ledger_a');
    DB::purge('ledger_b');
    if ($this->file !== null) {
        @unlink($this->file);
    }
});

/**
 * Before the n-th statement of worker A that `$match` accepts, run `$acts[n-1]` (statements issued by an act
 * itself are not counted). Returns the live counter of matching statements.
 *
 * @param  Closure(string): bool  $match  receives the lower-cased SQL
 * @param  list<Closure(): void>  $acts
 * @return ArrayObject<string, int|bool>
 */
$between = static function (Closure $match, array $acts, string $connection = 'ledger_a'): ArrayObject {
    $state = new ArrayObject(['seen' => 0, 'inside' => false]);
    DB::connection($connection)->beforeExecuting(static function (string $sql) use ($state, $match, $acts): void {
        if ($state['inside'] === true || ! $match(strtolower(ltrim($sql)))) {
            return;
        }
        $n = (int) $state['seen'];
        $state['seen'] = $n + 1;
        if (! isset($acts[$n])) {
            return;
        }
        $state['inside'] = true;
        try {
            $acts[$n]();
        } finally {
            $state['inside'] = false;
        }
    });

    return $state;
};

/** An UPDATE of the deliveries table whose WHERE clause names `$column`. */
$updateWhere = static fn (string $column): Closure => static function (string $sql) use ($column): bool {
    $where = strstr($sql, ' where ');

    return str_starts_with($sql, 'update') && str_contains($sql, 'mobile_push_deliveries') && $where !== false && str_contains($where, $column);
};

/** @param list<ClaimedDelivery> $claimed @return list<string> */
$ids = static fn (array $claimed): array => array_map(static fn (ClaimedDelivery $c): string => $c->id(), $claimed);

$pending = static fn (): int => DB::connection('ledger_a')->table('mobile_push_deliveries')->where('state', 'pending')->count();

// ------------------------------------------------------------------ optimistic claim rounds

it('a worker that loses its whole batch twice in a row takes the next batch on its third round', function () use ($between, $updateWhere, $ids, $pending): void {
    $this->a->ledger->recordIntent(H::intent(H::alert()), H::tokens(10));
    $byB = [];
    $steal = function () use (&$byB): void {
        array_push($byB, ...$this->b->claimIds('default', 'worker-b', 3));
    };
    $between($updateWhere('version'), [$steal, $steal]);

    $byA = $ids($this->a->ledger->claimDue('default', 'worker-a', 3));

    expect($byB)->toHaveCount(6)
        ->and($byA)->toHaveCount(3)
        ->and(array_intersect($byA, $byB))->toBe([])
        ->and($pending())->toBe(1);
});

it('a worker that loses its whole batch three rounds in a row gives up empty-handed; the next claim gets the rest', function () use ($between, $updateWhere, $ids, $pending): void {
    $this->a->ledger->recordIntent(H::intent(H::alert()), H::tokens(10));
    $byB = [];
    $steal = function () use (&$byB): void {
        array_push($byB, ...$this->b->claimIds('default', 'worker-b', 3));
    };
    $updates = $between($updateWhere('version'), [$steal, $steal, $steal]);

    $first = $this->a->ledger->claimDue('default', 'worker-a', 3);

    expect($first)->toBe([])
        ->and($updates['seen'])->toBe(3)
        ->and($byB)->toHaveCount(9)
        ->and($pending())->toBe(1);

    $next = $ids($this->a->ledger->claimDue('default', 'worker-a', 3));
    expect($next)->toHaveCount(1)
        ->and(array_intersect($next, $byB))->toBe([])
        ->and($pending())->toBe(0);
});

it('a worker that loses part of its batch tops it up to its limit, never beyond', function () use ($between, $updateWhere, $ids, $pending): void {
    $this->a->ledger->recordIntent(H::intent(H::alert()), H::tokens(10));
    $byB = [];
    $between($updateWhere('version'), [function () use (&$byB): void {
        array_push($byB, ...$this->b->claimIds('default', 'worker-b', 1));
    }]);

    $byA = $ids($this->a->ledger->claimDue('default', 'worker-a', 3));

    expect($byB)->toHaveCount(1)
        ->and($byA)->toHaveCount(3)
        ->and(array_intersect($byA, $byB))->toBe([])
        ->and(DB::connection('ledger_a')->table('mobile_push_deliveries')->where('lease_owner', 'worker-a')->count())->toBe(3)
        ->and($pending())->toBe(6);
});

// ------------------------------------------------------------------ record()

it('a cancel lands while the worker writes a retryable failure: the write is redone and the row ends cancelled', function () use ($between, $updateWhere): void {
    $effect = $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 1)[0];
    expect($this->a->ledger->markAttemptStarted($claimed->lease))->toBeTrue();
    $cancelled = null;
    $writes = $between($updateWhere('cancel_requested'), [function () use ($effect, &$cancelled): void {
        $cancelled = $this->b->ledger->cancelEffect('default', $effect->id);
    }]);

    $recording = $this->a->ledger->record($claimed->lease, $this->a->deferred($claimed, 30), $this->a->budgetAfterOneAttempt());
    $row = $this->a->ledger->delivery('default', $claimed->id());

    expect($cancelled)->toBe(1)
        ->and($recording)->toBe(Recording::Recorded)
        ->and($writes['seen'])->toBe(2)
        ->and($row?->state)->toBe(DeliveryState::Cancelled)
        ->and($row?->reason->code)->toBe(ReasonCode::Cancelled)
        ->and($row?->attempts)->toBe(1);
});

it('a cancel and then the sweeper both land while the worker writes: the worker is told the row is final', function () use ($between, $updateWhere): void {
    $effect = $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 1)[0];
    expect($this->a->ledger->markAttemptStarted($claimed->lease))->toBeTrue();
    $writes = $between($updateWhere('cancel_requested'), [
        fn () => $this->b->ledger->cancelEffect('default', $effect->id),
        function (): void {
            $this->b->expireLeases();
            expect($this->b->ledger->reclaimExpiredLeases('default')->endedUnknown)->toBe(1);
        },
    ]);

    $recording = $this->a->ledger->record($claimed->lease, $this->a->accepted($claimed, 'from-a'), $this->a->budgetAfterOneAttempt());
    $row = $this->a->ledger->delivery('default', $claimed->id());

    expect($recording)->toBe(Recording::IgnoredFinal)
        ->and($writes['seen'])->toBe(2)
        ->and($row?->state)->toBe(DeliveryState::OutcomeUnknown)
        ->and($row?->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($row?->providerMessageId)->toBeNull();
});

it('the same outcome is recorded twice at once with the same lease: one write wins, the other is told the row is final', function () use ($between, $updateWhere): void {
    $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 1)[0];
    expect($this->a->ledger->markAttemptStarted($claimed->lease))->toBeTrue();
    $byB = null;
    $between($updateWhere('cancel_requested'), [function () use ($claimed, &$byB): void {
        $byB = $this->b->ledger->record($claimed->lease, $this->b->accepted($claimed, 'apns-1'), $this->b->budgetAfterOneAttempt());
    }]);

    $byA = $this->a->ledger->record($claimed->lease, $this->a->accepted($claimed, 'apns-1-again'), $this->a->budgetAfterOneAttempt());
    $row = $this->a->ledger->delivery('default', $claimed->id());

    expect($byB)->toBe(Recording::Recorded)
        ->and($byA)->toBe(Recording::IgnoredFinal)
        ->and($row?->state)->toBe(DeliveryState::Accepted)
        ->and($row?->providerMessageId)->toBe('apns-1')
        ->and($row?->attempts)->toBe(1);
});

it('the lease is reclaimed and the row re-claimed while the worker writes: the late write is refused, the new owner records', function () use ($between, $updateWhere): void {
    $this->a->ledger->recordIntent(H::intent(H::liveUpdate()), [H::token(1)]);
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 1)[0];
    expect($this->a->ledger->markAttemptStarted($claimed->lease))->toBeTrue();
    $taken = [];
    $between($updateWhere('cancel_requested'), [function () use (&$taken): void {
        $this->b->expireLeases();
        $taken = $this->b->ledger->claimDue('default', 'worker-b', 1);
    }]);

    $late = $this->a->ledger->record($claimed->lease, $this->a->accepted($claimed, 'from-a'), $this->a->budgetAfterOneAttempt());

    expect($late)->toBe(Recording::IgnoredStaleLease)
        ->and($taken)->toHaveCount(1)
        ->and($this->b->ledger->markAttemptStarted($taken[0]->lease))->toBeTrue()
        ->and($this->b->ledger->record($taken[0]->lease, $this->b->accepted($taken[0], 'from-b'))->isRecorded())->toBeTrue()
        ->and($this->a->ledger->delivery('default', $claimed->id())?->providerMessageId)->toBe('from-b');
});

// ------------------------------------------------------------------ recordMany()

it('a window is recorded while one row is re-recorded by a duplicate job and another effect is cancelled: nothing is lost or written twice', function () use ($between, $updateWhere): void {
    $first = $this->a->ledger->recordIntent(H::intent(H::alert(), 'order-1'), [H::token(1), H::token(2)])->effect;
    $second = $this->a->ledger->recordIntent(H::intent(H::alert(), 'order-2'), [H::token(3)])->effect;
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 3);
    expect($this->a->ledger->markAttemptsStarted(array_map(static fn (ClaimedDelivery $c) => $c->lease, $claimed)))->toHaveCount(3);
    $byEffect = [];
    foreach ($claimed as $c) {
        $byEffect[$c->delivery->effectId][] = $c;
    }
    [$x, $y] = $byEffect[$first->id];
    [$z] = $byEffect[$second->id];
    $twoAttempts = $this->a->budgetAfterOneAttempt()->withAttempt();

    $between($updateWhere('cancel_requested'), [function () use ($x, $second): void {
        expect($this->b->ledger->record($x->lease, $this->b->accepted($x, 'x-by-duplicate'), $this->b->budgetAfterOneAttempt()))->toBe(Recording::Recorded)
            ->and($this->b->ledger->cancelEffect('default', $second->id))->toBe(1);
    }]);

    $out = $this->a->ledger->recordMany([
        [$x->lease, $this->a->accepted($x, 'x-by-a'), $this->a->budgetAfterOneAttempt()],
        [$y->lease, $this->a->accepted($y, 'y-by-a'), $this->a->budgetAfterOneAttempt()],
        [$z->lease, $this->a->deferred($z, 30), $twoAttempts],
    ]);
    $read = fn (ClaimedDelivery $c) => $this->a->ledger->delivery('default', $c->id());

    // Keyed by delivery id (the rows redone through record() come last).
    expect($out)->toHaveCount(3)
        ->and($out[$x->id()] ?? null)->toBe(Recording::IgnoredFinal)
        ->and($out[$y->id()] ?? null)->toBe(Recording::Recorded)
        ->and($out[$z->id()] ?? null)->toBe(Recording::Recorded)
        ->and($read($x)?->providerMessageId)->toBe('x-by-duplicate')
        ->and($read($y)?->state)->toBe(DeliveryState::Accepted)
        ->and($read($y)?->providerMessageId)->toBe('y-by-a')
        ->and($read($z)?->state)->toBe(DeliveryState::Cancelled)
        ->and($read($z)?->attempts)->toBe(2)
        ->and($read($z)?->budget?->attempts)->toBe(2);
});

// ------------------------------------------------------------------ markAttemptsStarted()

it('a cancel lands between the claim and the start of the window: only the leases still held are started', function () use ($between, $updateWhere): void {
    $first = $this->a->ledger->recordIntent(H::intent(H::alert(), 'order-1'), [H::token(1), H::token(2)])->effect;
    $second = $this->a->ledger->recordIntent(H::intent(H::alert(), 'order-2'), [H::token(3)])->effect;
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 3);
    $between($updateWhere('lease_expires_at'), [fn () => $this->b->ledger->cancelEffect('default', $second->id)]);

    $started = $this->a->ledger->markAttemptsStarted(array_map(static fn (ClaimedDelivery $c) => $c->lease, $claimed));

    $ofFirst = array_values(array_map(static fn (ClaimedDelivery $c): string => $c->id(), array_filter($claimed, static fn (ClaimedDelivery $c): bool => $c->delivery->effectId === $first->id)));
    $ofSecond = array_values(array_filter($claimed, static fn (ClaimedDelivery $c): bool => $c->delivery->effectId === $second->id))[0];
    sort($started);
    sort($ofFirst);
    expect($started)->toBe($ofFirst)
        ->and($this->a->ledger->delivery('default', $ofSecond->id())?->state)->toBe(DeliveryState::Cancelled)
        ->and($this->a->ledger->record($ofSecond->lease, $this->a->accepted($ofSecond)))->toBe(Recording::IgnoredFinal);
});

it('a window whose leases expired and were reclaimed before it started: nothing is started, the new owner keeps its row', function (): void {
    $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2)]);
    $claimed = $this->a->ledger->claimDue('default', 'worker-a', 2);
    $this->a->expireLeases();
    $taken = $this->b->ledger->claimDue('default', 'worker-b', 1);

    expect($this->a->ledger->markAttemptsStarted(array_map(static fn (ClaimedDelivery $c) => $c->lease, $claimed)))->toBe([])
        ->and($taken)->toHaveCount(1)
        ->and($this->b->ledger->markAttemptsStarted([$taken[0]->lease]))->toBe([$taken[0]->id()])
        ->and(DB::connection('ledger_a')->table('mobile_push_deliveries')->where('state', 'sending')->count())->toBe(1)
        ->and(DB::connection('ledger_a')->table('mobile_push_deliveries')->where('state', 'pending')->count())->toBe(1);
});

// ------------------------------------------------------------------ recordIntent() with the same key at once

// On SQLite the other caller runs on worker A's own connection (its transaction becomes a savepoint inside A's): a
// second SQLite connection cannot write while A's transaction holds its read lock. On a server database it is a
// real second connection that commits before A goes on.
it('two calls with the same key at once: the one that loses the unique index replays the winner\'s effect', function () use ($between): void {
    $other = DatabaseLedgerWorld::harness($this->onServer ? 'ledger_b' : 'ledger_a', ClaimStrategy::Optimistic, $this->clock, migrate: false);
    $winner = null;
    $between(static fn (string $sql): bool => str_starts_with($sql, 'insert') && str_contains($sql, 'mobile_push_effects'), [
        function () use ($other, &$winner): void {
            $winner = $other->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        },
    ]);

    $loser = $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2)]);

    expect($winner?->created)->toBeTrue()
        ->and($loser->created)->toBeFalse()
        ->and($loser->effect->id)->toBe($winner?->effect->id)
        ->and($loser->added)->toBe(1)
        ->and($loser->existing)->toBe(1)
        ->and($loser->previousEffectId)->toBeNull()
        ->and(DB::connection('ledger_a')->table('mobile_push_effects')->count())->toBe(1)
        ->and(DB::connection('ledger_a')->table('mobile_push_deliveries')->count())->toBe(2);
});

it('two calls reuse an expired key at once: one creates the new effect, the other replays it, the old effect is untouched', function () use ($between): void {
    $old = $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
    $this->clock->advance($this->a->settings->dedupeSeconds + 1);
    $other = DatabaseLedgerWorld::harness($this->onServer ? 'ledger_b' : 'ledger_a', ClaimStrategy::Optimistic, $this->clock, migrate: false);
    $winner = null;
    $between(static fn (string $sql): bool => str_starts_with($sql, 'update') && str_contains($sql, 'mobile_push_effects'), [
        function () use ($other, &$winner): void {
            $winner = $other->ledger->recordIntent(H::intent(H::alert()), [H::token(2)]);
        },
    ]);

    $loser = $this->a->ledger->recordIntent(H::intent(H::alert()), [H::token(2), H::token(3)]);

    expect($winner?->created)->toBeTrue()
        ->and($winner?->previousEffectId)->toBe($old->id)
        ->and($loser->created)->toBeFalse()
        ->and($loser->effect->id)->toBe($winner?->effect->id)
        ->and($loser->previousEffectId)->toBeNull()
        ->and($loser->added)->toBe(1)
        ->and($loser->existing)->toBe(1)
        ->and($this->a->ledger->counts('default', $old->id)->of(DeliveryState::Pending))->toBe(1)
        ->and($this->a->ledger->counts('default', $loser->effect->id)->of(DeliveryState::Pending))->toBe(2);
});
