<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStateException;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCancelled;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignPaused;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignResumed;
use Padosoft\MobilePush\Laravel\Campaigns\PlanCampaignJob;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;

require_once __DIR__.'/CampaignWorld.php';

/*
 * Pause, resume and cancel in the middle of a campaign of 2,000 devices planned in slices of 100.
 */

beforeEach(function (): void {
    Event::fake([CampaignPaused::class, CampaignResumed::class, CampaignCancelled::class]);
    $this->w = CampaignWorld::boot(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(2_000);
    $this->campaign = $this->w->dispatch(DeviceAudience::all());
    $this->id = $this->campaign->id;
    $this->runRelays = function (): void {
        foreach ($this->w->pending(RelayDeliveriesJob::class) as $job) {
            $this->w->lose($job);
            $this->w->rerun($job);
        }
    };
});

it('pause after 5 slices: nothing more is written, the 500 written still go out; resume continues from the cursor to exactly the audience', function (): void {
    $this->w->drain(5);   // five planner jobs
    $paused = $this->w->campaigns()->pause('default', $this->id);
    $again = $this->w->campaigns()->pause('default', $this->id);   // idempotent

    $this->w->drain();    // the queued 6th slice job finds the campaign paused; the relays run

    expect($paused->status)->toBe(CampaignStatus::Paused)
        ->and($again->version)->toBe($paused->version)
        ->and(CampaignWorld::deliveries($this->id))->toBe(500)
        ->and(CampaignWorld::states($this->id))->toBe(['accepted' => 500])
        ->and($this->w->requestHistogram(500))->toBe([0 => 0, 1 => 500, 'many' => 0])
        ->and($this->w->requestsOutside(500))->toBe(0)
        ->and((int) CampaignWorld::row($this->id)->slices)->toBe(5)
        ->and(CampaignWorld::row($this->id)->cursor)->toBe('id:500');
    Event::assertDispatchedTimes(CampaignPaused::class, 1);

    // Still paused an hour later: no sweeper or stray job plans anything.
    $this->w->queue->advance(3600);
    $this->w->campaigns()->sweep();
    $this->w->drain();
    expect(CampaignWorld::deliveries($this->id))->toBe(500);

    $resumed = $this->w->campaigns()->resume('default', $this->id);
    $this->w->drain();
    $this->w->campaigns()->sweep();   // completion is detected by the sweeper (or any progress() read)

    $row = CampaignWorld::row($this->id);
    expect($resumed->status)->toBe(CampaignStatus::Planning)
        ->and(CampaignWorld::deviceCoverage($this->id))->toBe(['devices' => 2_000, 'rows' => 2_000])
        ->and($this->w->requestHistogram(2_000))->toBe([0 => 0, 1 => 2_000, 'many' => 0])
        ->and($this->w->requestsOutside(2_000))->toBe(0)
        ->and((int) $row->slices)->toBe(20)            // 5 + 15: nothing recomputed from zero
        ->and((int) $row->planned)->toBe(2_000)
        ->and($row->status)->toBe('completed')
        ->and($this->w->campaigns()->reconcile('default', $this->id)->clean())->toBeTrue();
    Event::assertDispatchedTimes(CampaignResumed::class, 1);
});

it('cancel mid-way: waiting deliveries are cancelled in the ledger, nothing more is planned, the accepted stay accepted, and progress says so', function (): void {
    $this->w->drain(5);
    ($this->runRelays)();             // the first 500 are accepted
    $this->w->drain(3);               // three more slices written, not sent yet
    $sentBefore = $this->w->queue->transport->sentCount();

    $cancelled = $this->w->campaigns()->cancel('default', $this->id);
    $this->w->campaigns()->cancel('default', $this->id);   // idempotent
    $this->w->drain();
    $this->w->queue->advance(3600);
    $this->w->campaigns()->sweep();
    $this->w->drain();

    $progress = $this->w->campaigns()->progress('default', $this->id);
    expect($cancelled->status)->toBe(CampaignStatus::Cancelled)
        ->and(CampaignWorld::states($this->id))->toBe(['accepted' => 500, 'cancelled' => 300])
        ->and($this->w->queue->transport->sentCount())->toBe($sentBefore)
        ->and((int) CampaignWorld::row($this->id)->slices)->toBe(8)
        ->and($progress->accepted)->toBe(500)
        ->and($progress->cancelled)->toBe(300)
        ->and($progress->waiting + $progress->inFlight)->toBe(0)
        ->and($progress->campaign->status)->toBe(CampaignStatus::Cancelled)
        ->and(implode(' ', $progress->notes))->toContain('300 deliveries were cancelled before being sent; 500 accepted');
    Event::assertDispatchedTimes(CampaignCancelled::class, 1);
    Event::assertDispatched(CampaignCancelled::class, static fn (CampaignCancelled $e): bool => $e->cancelledDeliveries === 300 && $e->counts['accepted'] === 500);

    expect(fn () => $this->w->campaigns()->resume('default', $this->id))->toThrow(CampaignStateException::class);
});

it('a cancel that lands while a slice is being written also cancels what that slice wrote', function (): void {
    $this->w->drain(2);
    ($this->runRelays)();             // the first 200 are accepted
    // The cancel happens between the slice's ledger write and its checkpoint (the checkpoint then still commits).
    $cancelled = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$cancelled): void {
        if (! $cancelled && str_starts_with($sql, 'update "mobile_push_campaigns" set "cursor"')) {
            $cancelled = true;
            $this->w->campaigns()->cancel('default', $this->id);
        }
    });
    $this->w->drain();

    expect(CampaignWorld::states($this->id))->toBe(['accepted' => 200, 'cancelled' => 100])
        ->and((int) CampaignWorld::row($this->id)->planned)->toBe(300)
        ->and($this->w->campaigns()->reconcile('default', $this->id)->clean())->toBeTrue()
        ->and(Queue::pushed(PlanCampaignJob::class))->toHaveCount(3);
});
