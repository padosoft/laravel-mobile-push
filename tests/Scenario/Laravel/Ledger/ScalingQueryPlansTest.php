<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceContext;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../Campaigns/CampaignWorld.php';

/*
 * "Every hot query of the durable path reads only the rows it returns": the statements of an intent, a claim, the
 * start and the record of a window, the sweeps, the settlement probe, the campaign audience and the device lookup are
 * captured while they run and their SQLite query plan is checked. A full scan, a sort of every due row, or a
 * secondary index chosen over the primary key is what made a 500,000-device campaign super-linear (W5-P).
 */

/** @return list<array{sql: string, bindings: array<mixed>}> statements run by $work */
function capturedStatements(Closure $work): array
{
    $seen = [];
    DB::listen(static function (QueryExecuted $q) use (&$seen): void {
        $seen[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
    });
    $work();
    $listening = $seen;
    $seen = [];   // the listener stays registered for the test; later statements are ignored

    return $listening;
}

function queryPlan(string $sql, array $bindings): string
{
    return implode(' | ', array_map(static fn (object $r): string => (string) $r->detail, DB::select('explain query plan '.$sql, $bindings)));
}

/** @return list<string> one "plan <= sql" line per captured select/update on the hot tables */
function plansOf(array $statements): array
{
    $out = [];
    foreach ($statements as ['sql' => $sql, 'bindings' => $bindings]) {
        if (preg_match('/^(select|update|delete)/', $sql) !== 1 || preg_match('/"mobile_push_(deliveries|devices|effects)"/', $sql) !== 1) {
            continue;
        }
        $out[] = queryPlan($sql, $bindings).'  <=  '.$sql;
    }

    return array_values(array_unique($out));
}

beforeEach(function (): void {
    CampaignWorld::boot();
    CampaignWorld::seedDevices(1200);
    $this->ledger = app(Ledger::class);
    $this->binding = SendKit::apnsBinding();
    $intent = fn (string $key, ?string $campaign = null): EffectIntent => new EffectIntent($this->binding, PushMessage::make('Hi', 'There'), $key, campaignId: $campaign);

    // Some history: 800 final rows of an older effect, then the live effect with 1200 due rows.
    $old = $this->ledger->recordIntent($intent('history'), QueueWorld::tokens(800, 5000))->effect;
    foreach ($this->ledger->claimDue('default', 'old', 1000, $old->id) as $c) {
        $this->ledger->markAttemptStarted($c->lease);
        $this->ledger->record($c->lease, new SendResult($c->destination ?? QueueWorld::token(0), SendStatus::Accepted, Reason::of(ReasonCode::None), 1, deliveryId: $c->id()));
    }
    $this->intent = $intent;
    $this->live = $this->ledger->recordIntent($intent('live', 'camp-1'), (static function (): Generator {
        for ($i = 1; $i <= 1200; $i++) {
            yield QueueWorld::token($i)->withRef(new DestinationRef((string) $i, 1, 'user', (string) ($i - 1)));
        }
    })())->effect;
});

it('claims in index order, without sorting the due rows, and takes the claimed rows by primary key', function (): void {
    $statements = capturedStatements(function (): void {
        $this->ledger->claimDue('default', 'w1', 500, $this->live->id);   // one effect
        $this->ledger->claimDue('default', 'w2', 500);                    // the whole scope
    });
    $plans = plansOf($statements);

    $candidates = array_values(array_filter($plans, static fn (string $p): bool => str_contains($p, 'select "id", "version", "next_attempt_at"')));
    expect($candidates)->not->toBeEmpty();
    foreach ($candidates as $plan) {
        expect($plan)->not->toContain('TEMP B-TREE')
            ->and($plan)->toMatch('/USING (COVERING )?INDEX mp_deliveries_(effect_)?claim_idx/');
    }
    foreach (array_filter($plans, static fn (string $p): bool => str_contains($p, 'update "mobile_push_deliveries"')) as $plan) {
        expect($plan)->toContain('sqlite_autoindex_mobile_push_deliveries_1 (id=?)');   // the primary key, never a state index
    }
    foreach ($plans as $plan) {
        expect($plan)->not->toMatch('/^SCAN mobile_push_deliveries|\| SCAN mobile_push_deliveries/');
    }
});

it('starts and records a whole window by primary key', function (): void {
    $claimed = $this->ledger->claimDue('default', 'w1', 100, $this->live->id);
    assert($this->ledger instanceof DatabaseLedger);
    $statements = capturedStatements(function () use ($claimed): void {
        $this->ledger->markAttemptsStarted(array_map(static fn ($c) => $c->lease, $claimed));
        $this->ledger->recordMany(array_map(static fn ($c): array => [$c->lease, new SendResult(QueueWorld::token(1), SendStatus::Accepted, Reason::of(ReasonCode::None), 1, deliveryId: $c->id()), null], $claimed));
    });
    $plans = plansOf($statements);

    expect($plans)->not->toBeEmpty()
        // one start statement and one read for the 100 rows, then the 100 guarded writes (one transaction)
        ->and(count(array_filter($statements, static fn (array $s): bool => str_starts_with($s['sql'], 'update "mobile_push_deliveries" set "state" = ?, "attempt_started_at"'))))->toBe(1)
        ->and(count(array_filter($statements, static fn (array $s): bool => str_starts_with($s['sql'], 'select * from "mobile_push_deliveries" where "id" in'))))->toBe(1);
    foreach ($plans as $plan) {
        expect($plan)->toContain('sqlite_autoindex_mobile_push_deliveries_1 (id=?)');
    }
});

it('the sweeps, health and the settlement probe never read final rows', function (): void {
    assert($this->ledger instanceof DatabaseLedger);
    $statements = capturedStatements(function (): void {
        $this->ledger->scopesWithWork();
        $this->ledger->health();
        $this->ledger->health('default');
        $this->ledger->reclaimExpiredLeases('default');
        $this->ledger->expireDue('default');
        $this->ledger->hasOpenDeliveries('default', $this->live->id);
        $this->ledger->campaignHasOpenDeliveries('default', 'camp-1');
    });
    $plans = plansOf($statements);

    expect($plans)->not->toBeEmpty();
    foreach ($plans as $plan) {
        expect($plan)->not->toMatch('/(^|\| )SCAN mobile_push_deliveries/');
    }
    $work = array_values(array_filter($plans, static fn (string $p): bool => str_contains($p, 'select distinct "scope"') && str_contains($p, '"next_attempt_at" <= ?')));
    expect($work)->not->toBeEmpty();
    foreach ($work as $plan) {
        expect($plan)->toContain('mp_deliveries_work_idx');
    }
    $probe = array_values(array_filter($plans, static fn (string $p): bool => str_contains($p, 'exists') && str_contains($p, '"effect_id" = ?')));
    expect($probe)->toHaveCount(1)->and($probe[0])->toContain('mp_deliveries_effect_claim_idx');
});

it('a campaign slice reads its devices in key order on the audience index; the relay reads devices by primary key', function (): void {
    $audience = DeviceAudience::all();
    $context = new AudienceContext('default', $this->binding, 'camp-1');
    $bound = $audience->upperBound($context);
    $statements = capturedStatements(function () use ($audience, $context, $bound): void {
        $audience->slice($context, null, $bound, 500);
        (new DeviceEligibility)->resolveMany([new DestinationRef('3', 1, 'user', '2'), new DestinationRef('900', 1, 'user', '899')], $this->binding);
    });
    $plans = plansOf($statements);

    $slice = array_values(array_filter($plans, static fn (string $p): bool => str_contains($p, '"id" > ?')));
    $lookup = array_values(array_filter($plans, static fn (string $p): bool => str_contains($p, 'where "id" in')));
    expect($slice)->toHaveCount(1)->and($lookup)->toHaveCount(1)
        ->and($slice[0])->toContain('USING INDEX mp_devices_audience_idx (scope=? AND binding_identity=? AND state=? AND id>? AND id<?)')
        ->and($slice[0])->not->toContain('TEMP B-TREE')
        ->and($lookup[0])->toContain('USING INTEGER PRIMARY KEY');

    // The lookup still refuses a device of another binding (checked on the row read).
    $other = SendKit::apnsBinding(scope: 'tenant-b');
    expect((new DeviceEligibility)->resolveMany([new DestinationRef('3', 1, 'user', '2')], $other)['3']->eligible())->toBeFalse()
        ->and((new DeviceEligibility)->resolveMany([new DestinationRef('3', 1, 'user', '2')], $this->binding)['3']->eligible())->toBeTrue();
});
