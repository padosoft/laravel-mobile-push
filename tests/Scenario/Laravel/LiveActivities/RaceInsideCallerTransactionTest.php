<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ClientActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushToStartToken;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

/*
 * "The application calls the registry inside its own transaction, and another request changes the same row
 * meanwhile." Server engines only (the MySQL / PostgreSQL CI jobs): the other request is a real second connection
 * that commits while the first one's transaction is open.
 *
 * At MySQL's default REPEATABLE READ the open transaction answers plain reads from the snapshot it took at its first
 * read, so a re-read after a lost write must be a locking read or it keeps seeing the row as it was.
 */
beforeEach(function (): void {
    $default = (string) config('database.default');
    if (! in_array(config("database.connections.{$default}.driver"), ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Needs a server database: two connections write at once.');
    }
    foreach (['race_a', 'race_b'] as $name) {
        config()->set("database.connections.{$name}", config("database.connections.{$default}"));
    }
    $this->world = LiveWorld::install();
    config()->set('mobile-push.live_activities.connection', 'race_a');

    /** Run $work as "request B": on its own connection, committed before it returns. */
    $this->asOtherRequest = static function (Closure $work): mixed {
        config()->set('mobile-push.live_activities.connection', 'race_b');
        try {
            return $work();
        } finally {
            config()->set('mobile-push.live_activities.connection', 'race_a');
        }
    };
});

afterEach(function (): void {
    DB::purge('race_a');
    DB::purge('race_b');
});

it('registers a push-to-start token inside the caller\'s transaction while another request commits the same token: one row, no exception', function (): void {
    $binding = $this->world->binding();
    $token = LiveWorld::pushToStart('race');
    $fired = false;
    $other = $this->asOtherRequest;

    PushToStartToken::creating(function () use (&$fired, $other, $binding, $token): void {
        if ($fired) {
            return;
        }
        $fired = true;
        $other(fn () => LiveWorld::rider(2)->registerPushToStartToken($binding, DeliveryActivity::class, $token));
    });

    $row = DB::connection('race_a')->transaction(function () use ($binding, $token): PushToStartToken {
        // The application's own work comes first: this read fixes the snapshot at REPEATABLE READ.
        expect(PushToStartToken::query()->count())->toBe(0);

        return LiveWorld::rider(1)->registerPushToStartToken($binding, DeliveryActivity::class, $token);
    });

    $rows = DB::connection('race_b')->table($row->getTable())->get();
    expect($fired)->toBeTrue()
        ->and($rows)->toHaveCount(1)
        ->and((string) $rows[0]->owner_id)->toBe('1')
        // Request B registered it for rider 2 first: ours is a takeover of that row.
        ->and((int) $rows[0]->generation)->toBe(2)
        ->and($row->generation)->toBe(2);
});

it('reports an activity token inside the caller\'s transaction while another request commits the same token: one activity, no exception', function (): void {
    $binding = $this->world->binding();
    $token = LiveWorld::activityToken('race');
    $fired = false;
    $other = $this->asOtherRequest;

    PushActivity::creating(function () use (&$fired, $other, $binding, $token): void {
        if ($fired) {
            return;
        }
        $fired = true;
        $other(fn () => LiveWorld::rider(1)->reportLiveActivityToken($binding, DeliveryActivity::class, $token, new ActivityReport(clientActivityId: 'RACE-TOKEN')));
    });

    $activity = DB::connection('race_a')->transaction(function () use ($binding, $token): PushActivity {
        expect(PushActivity::query()->count())->toBe(0);

        return LiveWorld::rider(1)->reportLiveActivityToken($binding, DeliveryActivity::class, $token, new ActivityReport(clientActivityId: 'RACE-TOKEN'));
    });

    $rows = DB::connection('race_b')->table($activity->getTable())->get();
    expect($fired)->toBeTrue()
        ->and($rows)->toHaveCount(1)
        ->and((string) $rows[0]->id)->toBe((string) $activity->id)
        ->and((string) $rows[0]->client_activity_id)->toBe('RACE-TOKEN');
});

it('records a client report inside the caller\'s transaction after another request changed the activity: the later report is stored', function (): void {
    $binding = $this->world->binding();
    $activity = LiveWorld::rider(1)->reportLiveActivityToken($binding, DeliveryActivity::class, LiveWorld::activityToken('race'), new ActivityReport(clientActivityId: 'RACE-TX', state: ['eta' => 10]));
    $clock = $this->world->kit->clock;
    $clock->advance(1);
    $first = $clock->now();
    $clock->advance(5);
    $second = $clock->now();
    $registry = $this->world->registry();
    $other = $this->asOtherRequest;

    $stored = DB::connection('race_a')->transaction(function () use ($activity, $registry, $other, $first, $second): PushActivity {
        // The application reads the activity first: at REPEATABLE READ this is the version its transaction keeps seeing.
        $mine = PushActivity::query()->findOrFail($activity->id);

        // Request B reports "active" and commits: the row's version moves on.
        $other(fn () => $registry->reportClientState(PushActivity::query()->findOrFail($activity->id), ClientActivityState::Active, $first));

        return $registry->reportClientState($mine, ClientActivityState::Ended, $second);
    });

    $row = DB::connection('race_b')->table($stored->getTable())->where('id', $activity->id)->first();
    expect($row->client_reported_state)->toBe(ClientActivityState::Ended->value)
        ->and($stored->version)->toBeGreaterThan($activity->version);
});
