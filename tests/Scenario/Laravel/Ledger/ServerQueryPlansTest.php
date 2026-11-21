<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceContext;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../Campaigns/CampaignWorld.php';

/*
 * The same question as ScalingQueryPlansTest ("does a claim read only the rows it returns?"), asked to the planner of
 * a server engine: PostgreSQL and MySQL, in the CI jobs that run on them. On SQLite this file is skipped.
 *
 * A planner is free to prefer a scan on a small table, so the fixture is made big enough for the answer to matter
 * (history rows that are final, then several thousand due rows) and the statistics are refreshed before explaining.
 */

const SERVER_PLAN_DUE_ROWS = 6000;

/** @return list<array{sql: string, bindings: array<mixed>}> */
function serverCaptured(Closure $work): array
{
    $seen = [];
    $on = true;
    DB::listen(static function (QueryExecuted $q) use (&$seen, &$on): void {
        if ($on) {
            $seen[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
        }
    });
    $work();
    $on = false;

    return $seen;
}

/** The engine's plan as one JSON string. */
function serverPlan(string $sql, array $bindings): string
{
    $driver = DB::connection()->getDriverName();
    $rows = DB::select(($driver === 'pgsql' ? 'explain (format json) ' : 'explain format=json ').$sql, $bindings);

    return implode("\n", array_map(static fn (object $row): string => (string) array_values((array) $row)[0], $rows));
}

/**
 * Whether the engine has an index it can serve the statement with, for the given table.
 *
 * On a fixture of a few thousand rows a planner may still prefer to read the table (most rows match, or the table is
 * a handful of pages), so "which plan was chosen" says little. What does not depend on the size is whether an index
 * CAN serve the statement: PostgreSQL is asked with sequential scans disabled (it then scans the table only when no
 * index is usable); MySQL reports the usable indexes of each table in "possible_keys".
 */
function serverCanUseAnIndex(string $sql, array $bindings, string $table): bool
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('set enable_seqscan = off');
        try {
            $plan = serverPlan($sql, $bindings);
        } finally {
            DB::statement('reset enable_seqscan');
        }

        return preg_match('/"Node Type": "Seq Scan"[^}]*"Relation Name": "'.$table.'"/s', $plan) !== 1;
    }

    return preg_match('/"table_name": "'.$table.'"[^{}]*"possible_keys": \[\s*"/s', serverPlan($sql, $bindings)) === 1;
}

beforeEach(function (): void {
    $this->driver = DB::connection()->getDriverName();
    if (! in_array($this->driver, ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Server query plans are checked on PostgreSQL and MySQL only.');
    }
    CampaignWorld::boot();
    $this->ledger = app(Ledger::class);
    $binding = $this->binding = SendKit::apnsBinding();
    $intent = static fn (string $key, ?string $campaign = null): EffectIntent => new EffectIntent($binding, PushMessage::make('Hi', 'There'), $key, campaignId: $campaign);
    CampaignWorld::seedDevices(SERVER_PLAN_DUE_ROWS);

    $old = $this->ledger->recordIntent($intent('history'), QueueWorld::tokens(1500, 50_000))->effect;
    foreach ($this->ledger->claimDue('default', 'old', 2000, $old->id) as $c) {
        $this->ledger->markAttemptStarted($c->lease);
        $this->ledger->record($c->lease, new SendResult($c->destination ?? QueueWorld::token(0), SendStatus::Accepted, Reason::of(ReasonCode::None), 1, deliveryId: $c->id()));
    }
    $this->live = $this->ledger->recordIntent($intent('live', 'camp-1'), (static function (): Generator {
        for ($i = 1; $i <= SERVER_PLAN_DUE_ROWS; $i++) {
            yield QueueWorld::token($i)->withRef(new DestinationRef((string) $i, 1, 'user', (string) ($i - 1)));
        }
    })())->effect;

    foreach (['mobile_push_deliveries', 'mobile_push_devices', 'mobile_push_effects'] as $table) {
        DB::statement(($this->driver === 'pgsql' ? 'analyze ' : 'analyze table ').$table);
    }
});

it('claims through the claim indexes, in index order, on a server engine', function (): void {
    $statements = serverCaptured(function (): void {
        $this->ledger->claimDue('default', 'w1', 200, $this->live->id);   // one effect
        $this->ledger->claimDue('default', 'w2', 200);                    // the whole scope
    });
    $candidates = array_values(array_filter($statements, static fn (array $s): bool => preg_match('/^select .id., .version., .next_attempt_at. from .mobile_push_deliveries./', $s['sql']) === 1));
    expect($candidates)->not->toBeEmpty();

    $pending = 0;
    foreach ($candidates as ['sql' => $sql, 'bindings' => $bindings]) {
        $plan = serverPlan($sql, $bindings);
        // Never a read of the whole table, whatever the state asked for.
        expect($plan)->not->toContain($this->driver === 'pgsql' ? '"Seq Scan"' : '"access_type": "ALL"');
        if (! in_array('pending', $bindings, true)) {
            // A state with no rows: the planner may take any index that starts with (scope, state) and sort the
            // row or two it expects. Only the state that has thousands of due rows shows how a claim scales.
            continue;
        }
        $pending++;
        expect($plan)->toMatch('/mp_deliveries_(effect_)?claim_idx/', 'the claim of the due rows must be served by a claim index: '.$plan);
        if ($this->driver === 'pgsql') {
            expect($plan)->not->toContain('"Sort"');
        } else {
            expect($plan)->not->toMatch('/"using_filesort":\s*true/');
        }
    }
    expect($pending)->toBe(2);   // one per claim: the effect's and the scope's
});

it('leases the claimed rows without walking a state index, on a server engine', function (): void {
    $statements = serverCaptured(function (): void {
        $this->ledger->claimDue('default', 'w1', 200, $this->live->id);
    });
    $updates = array_values(array_filter($statements, static fn (array $s): bool => preg_match('/^update .mobile_push_deliveries./', $s['sql']) === 1));
    expect($updates)->not->toBeEmpty();

    foreach ($updates as ['sql' => $sql, 'bindings' => $bindings]) {
        $plan = serverPlan($sql, $bindings);
        // The rows are addressed by id: the plan may use the primary key (or, on a small table, read the table),
        // but never one of the state indexes, which is what made claims slower as the table grew.
        expect($plan)->not->toMatch('/mp_deliveries_(prune|work|claim|effect_claim)_idx/', $plan);
    }
});

it('the sweeps, health and the settlement probes never read the whole deliveries table, on a server engine', function (): void {
    assert($this->ledger instanceof DatabaseLedger);
    $statements = serverCaptured(function (): void {
        $this->ledger->scopesWithWork();
        $this->ledger->health('default');
        $this->ledger->reclaimExpiredLeases('default');
        $this->ledger->expireDue('default');
        $this->ledger->hasOpenDeliveries('default', $this->live->id);
        $this->ledger->campaignHasOpenDeliveries('default', 'camp-1');
    });
    $hot = array_values(array_filter($statements, static fn (array $s): bool => preg_match('/^(select|update|delete)/', $s['sql']) === 1 && str_contains($s['sql'], 'mobile_push_deliveries')));
    expect($hot)->not->toBeEmpty();

    foreach ($hot as ['sql' => $sql, 'bindings' => $bindings]) {
        expect(serverCanUseAnIndex($sql, $bindings, 'mobile_push_deliveries'))->toBeTrue('no index can serve: '.$sql);
    }
});

it('starts and records a window by primary key, on a server engine', function (): void {
    assert($this->ledger instanceof DatabaseLedger);
    $claimed = $this->ledger->claimDue('default', 'w1', 100, $this->live->id);
    $statements = serverCaptured(function () use ($claimed): void {
        $this->ledger->markAttemptsStarted(array_map(static fn ($c) => $c->lease, $claimed));
        $this->ledger->recordMany(array_map(static fn ($c): array => [$c->lease, new SendResult(QueueWorld::token(1), SendStatus::Accepted, Reason::of(ReasonCode::None), 1, deliveryId: $c->id()), null], $claimed));
    });
    $hot = array_values(array_filter($statements, static fn (array $s): bool => preg_match('/^(select|update)/', $s['sql']) === 1 && str_contains($s['sql'], 'mobile_push_deliveries')));
    expect($hot)->not->toBeEmpty();

    foreach ($hot as ['sql' => $sql, 'bindings' => $bindings]) {
        $plan = serverPlan($sql, $bindings);
        expect(serverCanUseAnIndex($sql, $bindings, 'mobile_push_deliveries'))->toBeTrue('no index can serve: '.$sql)
            ->and($plan)->not->toMatch('/mp_deliveries_(prune|work|claim|effect_claim)_idx/', $plan);
    }
});

it('reads a campaign slice on the audience index and the devices of a chunk by primary key, on a server engine', function (): void {
    $audience = DeviceAudience::all();
    $context = new AudienceContext('default', $this->binding, 'camp-1');
    $bound = $audience->upperBound($context);
    $statements = serverCaptured(function () use ($audience, $context, $bound): void {
        $audience->slice($context, null, $bound, 500);
        (new DeviceEligibility)->resolveMany([new DestinationRef('3', 1, 'user', '2'), new DestinationRef('900', 1, 'user', '899')], $this->binding);
    });
    $slice = array_values(array_filter($statements, static fn (array $s): bool => str_contains($s['sql'], 'mobile_push_devices') && preg_match('/.id. > \?/', $s['sql']) === 1));
    $lookup = array_values(array_filter($statements, static fn (array $s): bool => str_contains($s['sql'], 'mobile_push_devices') && preg_match('/where .id. in/', $s['sql']) === 1));
    expect($slice)->toHaveCount(1)->and($lookup)->toHaveCount(1);

    expect(serverCanUseAnIndex($slice[0]['sql'], $slice[0]['bindings'], 'mobile_push_devices'))->toBeTrue('no index can serve the audience slice')
        ->and(serverCanUseAnIndex($lookup[0]['sql'], $lookup[0]['bindings'], 'mobile_push_devices'))->toBeTrue('no index can serve the device lookup');
});
