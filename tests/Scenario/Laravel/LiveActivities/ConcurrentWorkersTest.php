<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

/*
 * Two workers update the same activity at once. The race is injected for real at the database: the second worker
 * runs completely in the gap between the first worker's read of the row and its compare-and-set claim, so the first
 * worker's claim finds a newer row version.
 */
beforeEach(function (): void {
    $this->world = LiveWorld::install();
    $this->activity = LiveWorld::rider(1)->reportLiveActivityToken($this->world->binding(), DeliveryActivity::class, LiveWorld::activityToken('w1'), new ActivityReport(clientActivityId: 'RACE-1', state: ['eta' => 10]));
    $this->world->kit->clock->advance(1);
});

/** Run $other once, just before the next claim statement of the current worker reaches the database. */
function raceBeforeClaim(Closure $other): void
{
    $fired = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$fired, $other): void {
        if ($fired || ! str_starts_with(strtolower($sql), 'update') || ! str_contains($sql, 'applied_seq')) {
            return;
        }
        $fired = true;
        $other();
    });
}

function contentStates(LiveWorld $world): array
{
    return array_map(static fn ($r) => LiveWorld::aps($r)['content-state'], $world->pushes());
}

it('the later-dispatched update wins; the earlier one is superseded and never reaches the network', function (): void {
    $activity = $this->activity;
    raceBeforeClaim(function () use ($activity, &$b): void {
        $b = LiveActivity::for(PushActivity::query()->find($activity->id))->update(['eta' => 'B'])->send();
    });

    $a = LiveActivity::for($activity)->update(['eta' => 'A'])->send();

    expect($b->status)->toBe(SendStatus::Accepted)
        ->and($a->outcome)->toBe(TransitionOutcome::Skipped)
        ->and($a->reason->code)->toBe(ReasonCode::Cancelled)
        ->and(contentStates($this->world))->toBe([['eta' => 'B']]);

    $row = $activity->fresh();
    expect($row->last_content_state)->toBe(['eta' => 'B'])
        ->and($row->last_timestamp)->toBe(LiveWorld::aps($this->world->pushes()[0])['timestamp']);
});

it('an older queued update that wins the race goes first; the newer one is coalesced into the next second, never interleaved', function (): void {
    $activity = $this->activity;
    $older = LiveActivity::for($activity)->update(['eta' => 'old'])->ticket();
    raceBeforeClaim(function () use ($older, &$b): void {
        $b = LiveActivity::replay($older);
    });

    $a = LiveActivity::for($activity)->update(['eta' => 'new'])->send();

    expect($b->status)->toBe(SendStatus::Accepted)
        ->and($a->outcome)->toBe(TransitionOutcome::Deferred)
        ->and(contentStates($this->world))->toBe([['eta' => 'old']])
        ->and($activity->fresh()->last_content_state)->toBe(['eta' => 'old']);

    $this->world->kit->clock->advance(1);
    $this->world->manager()->flushDue();

    $pushes = $this->world->pushes();
    expect(contentStates($this->world))->toBe([['eta' => 'old'], ['eta' => 'new']])
        ->and(LiveWorld::aps($pushes[1])['timestamp'])->toBeGreaterThan(LiveWorld::aps($pushes[0])['timestamp']);

    $row = $activity->fresh();
    expect($row->last_content_state)->toBe(['eta' => 'new'])
        ->and($row->last_timestamp)->toBe(LiveWorld::aps($pushes[1])['timestamp']);

    // The old ticket replayed again (a duplicate job) is stale.
    expect(LiveActivity::replay($older)->outcome)->toBe(TransitionOutcome::Skipped)
        ->and($this->world->pushes())->toHaveCount(2);
});
