<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Ledger\ClientEvent;
use Padosoft\MobilePush\Core\Ledger\ClientEventRecording;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/*
 * What the database ledger writes and how many statements it costs, read back from the connection's query log and
 * from the stored rows: chunked inserts, the legacy-key lookup, refused late writes, empty batches, the receipt
 * marker, the released dedupe key, and batches of leases from several workers.
 */

/**
 * Run $work with the query log on and return the statements on the deliveries table.
 *
 * @return list<array{query: string, bindings: array<mixed>}>
 */
$statements = static function (H $h, Closure $work): array {
    $connection = $h->ledger instanceof DatabaseLedger ? $h->ledger->connection() : throw new LogicException('database only');
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    try {
        $work();
    } finally {
        $connection->disableQueryLog();
    }

    return array_values(array_filter(
        $connection->getQueryLog(),
        static fn (array $q): bool => str_contains((string) $q['query'], 'mobile_push_deliveries'),
    ));
};

$verb = static fn (array $q): string => strtolower(strtok(ltrim((string) $q['query']), ' ') ?: '');

/** Rows of a multi-row INSERT: one "(?, ?, ...)" group each. */
$rowsOf = static fn (array $q): int => substr_count((string) $q['query'], '), (') + 1;

it('a ledger pointed at a connection that is not a query-builder connection says so', function (): void {
    [$index, $cipher] = DatabaseLedgerWorld::keys();
    $resolver = new class implements ConnectionResolverInterface
    {
        public function connection($name = null): ConnectionInterface
        {
            return Mockery::mock(ConnectionInterface::class);
        }

        public function getDefaultConnection(): string
        {
            return 'other';
        }

        public function setDefaultConnection($name): void {}
    };
    $ledger = new DatabaseLedger($resolver, $index, $cipher, new FakeClock);

    expect(fn () => $ledger->connection())->toThrow(LogicException::class, 'The ledger needs an Illuminate\Database\Connection.');
});

it('an intent of exactly one chunk, or of none, reports what it added and what already existed', function (): void {
    $h = DatabaseLedgerWorld::harness();
    $chunk = $h->settings->chunkSize;

    $first = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens($chunk));
    $replay = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens($chunk));
    $empty = $h->ledger->recordIntent(H::intent(H::alert(), 'nobody'), []);

    expect([$first->added, $first->existing])->toBe([$chunk, 0])
        ->and([$replay->added, $replay->existing])->toBe([0, $chunk])
        ->and([$empty->added, $empty->existing])->toBe([0, 0])
        ->and($h->ledger->counts('default', $first->effect->id)->total())->toBe($chunk);
});

it('a large intent is written one chunk per INSERT, each statement holding at most chunk-size rows', function () use ($statements, $verb, $rowsOf): void {
    $h = DatabaseLedgerWorld::harness();
    expect($h->settings->chunkSize)->toBe(100);

    $log = $statements($h, fn () => $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(250)));
    $inserts = array_values(array_filter($log, static fn (array $q): bool => $verb($q) === 'insert'));

    expect(array_map($rowsOf, $inserts))->toBe([100, 100, 50]);
});

it('a chunk bigger than the bound-parameter budget is split into INSERTs of at most 30000 values', function () use ($statements, $verb, $rowsOf): void {
    $h = DatabaseLedgerWorld::harness(settings: new LedgerSettings(leaseSeconds: 60, dedupeSeconds: 3600, chunkSize: 2000, retry: new RetryPolicy(maxAttempts: 5, maxElapsedSeconds: 3600)));

    $log = $statements($h, fn () => $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(2000)));
    $inserts = array_values(array_filter($log, static fn (array $q): bool => $verb($q) === 'insert'));
    $rows = array_map($rowsOf, $inserts);

    expect(array_sum($rows))->toBe(2000)
        ->and(count($inserts))->toBeGreaterThan(1)
        ->and(max(array_map(static fn (array $q): int => count($q['bindings']), $inserts)))->toBeLessThanOrEqual(30_000)
        // As few statements as the budget allows: every one but the last is full.
        ->and(count(array_unique(array_slice($rows, 0, -1))))->toBe(1)
        ->and(end($rows))->toBeLessThanOrEqual($rows[0]);
});

it('a replay looks up pre-W5-P keys only for registry devices: one query per chunk for devices, none for raw tokens', function () use ($statements, $verb): void {
    $h = DatabaseLedgerWorld::harness();
    $legacyLookups = static fn (array $log) => count(array_filter($log, static fn (array $q): bool => $verb($q) === 'select' && str_contains((string) $q['query'], 'logical_key')));

    $h->ledger->recordIntent(H::intent(H::alert(), 'raw'), H::tokens(3));
    $rawReplay = $statements($h, fn () => $h->ledger->recordIntent(H::intent(H::alert(), 'raw'), H::tokens(3)));
    $h->ledger->recordIntent(H::intent(H::alert(), 'devices'), [H::device(1), H::device(2)]);
    $deviceReplay = $statements($h, fn () => $h->ledger->recordIntent(H::intent(H::alert(), 'devices'), [H::device(1), H::device(2)]));
    $firstUse = $statements($h, fn () => $h->ledger->recordIntent(H::intent(H::alert(), 'new'), [H::device(3)]));

    expect($legacyLookups($rawReplay))->toBe(0)
        ->and($legacyLookups($deviceReplay))->toBe(1)
        ->and($legacyLookups($firstUse))->toBe(0);
});

it('a late write with the worker\'s own previous lease is refused on the read: no UPDATE is attempted', function () use ($statements, $verb): void {
    $h = DatabaseLedgerWorld::harness();
    $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
    $old = $h->ledger->claimDue('default', 'worker-a', 1)[0];
    $h->expireLeases();
    // The same worker (same name, e.g. the job ran again) claims the row once more: same owner, newer version.
    $again = $h->ledger->claimDue('default', 'worker-a', 1)[0];
    expect($again->lease->version)->toBeGreaterThan($old->lease->version);

    $recording = null;
    $log = $statements($h, function () use ($h, $old, &$recording): void {
        $recording = $h->ledger->record($old->lease, $h->accepted($old, 'late'));
    });

    expect($recording)->toBe(Recording::IgnoredStaleLease)
        ->and(array_map($verb, $log))->toBe(['select'])
        ->and($h->ledger->markAttemptStarted($again->lease))->toBeTrue()
        ->and($h->ledger->record($again->lease, $h->accepted($again, 'current')))->toBe(Recording::Recorded)
        ->and($h->ledger->delivery('default', $again->id())?->providerMessageId)->toBe('current');
});

it('an empty window costs no statement', function () use ($statements): void {
    $h = DatabaseLedgerWorld::harness();
    $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
    assert($h->ledger instanceof DatabaseLedger);

    $started = $recorded = null;
    $log = $statements($h, function () use ($h, &$started, &$recorded): void {
        $started = $h->ledger->markAttemptsStarted([]);
        $recorded = $h->ledger->recordMany([]);
    });

    expect($started)->toBe([])->and($recorded)->toBe([])->and($log)->toBe([]);
});

it('a client report on a push sent without a marker proves one was embedded: the marker is stored', function (): void {
    $h = DatabaseLedgerWorld::harness();
    $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
    $h->acceptAll('default', 'worker');
    $id = $h->rows()['deliveries'][0]['id'];
    expect((bool) $h->deliveryRow($id)['receipt_marker'])->toBeFalse();
    assert($h->ledger instanceof DatabaseLedger);

    $recorded = $h->ledger->recordClientEvent('default', $id, ClientEvent::Received, $h->clock->now());

    expect($recorded)->toBe(ClientEventRecording::Recorded)
        ->and((bool) $h->deliveryRow($id)['receipt_marker'])->toBeTrue()
        ->and($h->ledger->effectFunnel('default', $effect->id)->acceptedWithMarker)->toBe(1);
});

it('the earlier effect records when its expired key was released', function (): void {
    $h = DatabaseLedgerWorld::harness();
    $old = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
    $h->clock->advance($h->settings->dedupeSeconds + 1);
    $releasedAt = (int) $h->clock->now()->format('Uv');

    $new = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
    $row = array_values(array_filter($h->rows()['effects'], static fn (array $e): bool => $e['id'] === $old->id))[0];

    expect($new->previousEffectId)->toBe($old->id)
        ->and($row['dedupe_key'])->toBeNull()
        ->and((int) $row['updated_at'])->toBe($releasedAt)
        ->and((int) $row['created_at'])->toBeLessThan($releasedAt);
});

it('a batch of leases from workers, scopes and versions whose names run together starts every lease', function (): void {
    $h = DatabaseLedgerWorld::harness(strategy: ClaimStrategy::Optimistic);
    $claim = static function (string $scope, string $owner, int $cycles = 0) use ($h): ClaimedDelivery {
        $claimed = $h->ledger->claimDue($scope, $owner, 1)[0];
        for ($i = 0; $i < $cycles; $i++) {
            // The lease runs out before the attempt starts; the same worker takes the row again (version + 2).
            $h->expireLeases();
            $claimed = $h->ledger->claimDue($scope, $owner, 1)[0];
        }

        return $claimed;
    };
    foreach (['aa', 'a', '1c', 'c', 'b'] as $scope) {
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', $scope), [H::token(1)]);
    }
    $startsBoth = static function (ClaimedDelivery $one, ClaimedDelivery $two) use ($h): void {
        $started = $h->ledger->markAttemptsStarted([$one->lease, $two->lease]);
        sort($started);
        $expected = [$one->id(), $two->id()];
        sort($expected);
        expect($started)->toBe($expected);
    };

    // scope "aa" + owner "a" and scope "a" + owner "aa": same characters, the parts in another order.
    $startsBoth($claim('aa', 'a'), $claim('a', 'aa'));
    // owner "w" at version 11 (five lost leases) and owner "w1" at version 1, same scope.
    $w = $claim('b', 'w', 5);
    expect($w->lease->version)->toBe(11);
    $h->ledger->recordIntent(H::intent(H::alert(), 'k2', 'b'), [H::token(2)]);
    $startsBoth($w, $claim('b', 'w1'));
    // version 11 in scope "c" and version 1 in scope "1c", same owner.
    $startsBoth($claim('c', 'v', 5), $claim('1c', 'v'));
});
