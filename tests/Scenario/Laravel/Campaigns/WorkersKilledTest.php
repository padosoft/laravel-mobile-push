<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\PlanCampaignJob;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;

require_once __DIR__.'/CampaignWorld.php';

/*
 * "Workers killed at random" (plan §11, "Campagna grande con worker arrestati"): the planner and the relay die at
 * chosen points and are run again. The invariant (planned = deliveries in the ledger, any state) is checked against
 * the ledger table at every checkpoint, and at the end every device has exactly one delivery and at most one request.
 */

const DEVICES = 1_000;   // slices of 100: 10 planner jobs

beforeEach(function (): void {
    $this->w = CampaignWorld::boot(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(DEVICES);
    $this->campaign = $this->w->dispatch(DeviceAudience::all());

    /** The invariant at a checkpoint: the checkpoint equals the ledger, or a slice is being written right now. */
    $this->invariant = function (): void {
        $report = $this->w->campaigns()->reconcile('default', $this->campaign->id);
        expect($report->explained())->toBeTrue($report->describe())
            ->and($report->ledger)->toBe(CampaignWorld::deliveries($this->campaign->id))
            ->and($report->stray())->toBe(0);
    };
    $this->drainChecked = fn (?int $jobs = null): int => $this->w->drain($jobs, fn () => ($this->invariant)());

    $this->finalChecks = function (): void {
        $id = $this->campaign->id;
        $report = $this->w->campaigns()->reconcile('default', $id);
        $progress = $this->w->campaigns()->progress('default', $id);
        $histogram = $this->w->requestHistogram(DEVICES);

        expect($report->clean())->toBeTrue($report->describe())
            ->and($report->planned)->toBe(DEVICES)
            ->and(CampaignWorld::deviceCoverage($id))->toBe(['devices' => DEVICES, 'rows' => DEVICES])  // none skipped, none twice
            ->and($histogram['many'])->toBe(0)                                                         // never sent twice
            ->and($progress->waiting + $progress->inFlight)->toBe(0)
            ->and($progress->campaign->status)->toBe(CampaignStatus::Completed);
    };
});

it('dies after writing a slice but before advancing the cursor: the rerun inserts nothing twice and recounts the checkpoint', function (): void {
    ($this->drainChecked)(3);   // 3 slices planned (one job each)
    CampaignWorld::killOn('/^update "mobile_push_campaigns" set "cursor"/');
    $killed = $this->w->runUntilKilled();

    // The slice is in the ledger, the checkpoint is not: an explained difference, with the lease still held.
    $report = $this->w->campaigns()->reconcile('default', $this->campaign->id);
    expect($killed)->toBeInstanceOf(PlanCampaignJob::class)
        ->and($report->planned)->toBe(300)
        ->and($report->ledger)->toBe(400)
        ->and($report->sliceInProgress)->toBeTrue()
        ->and($report->explained())->toBeTrue();

    // The queue redelivers the job: while the lease is valid it does nothing; once expired it recovers.
    $this->w->rerun($killed);
    expect((int) CampaignWorld::row($this->campaign->id)->slices)->toBe(3);
    $this->w->queue->advance(151);
    $this->w->rerun($killed);

    $row = CampaignWorld::row($this->campaign->id);
    expect((int) $row->planned)->toBe(400)
        ->and((int) $row->slices)->toBe(4)
        ->and(CampaignWorld::deliveries($this->campaign->id))->toBe(400);

    ($this->drainChecked)();
    ($this->finalChecks)();
    expect($this->w->requestHistogram(DEVICES))->toBe([0 => 0, 1 => DEVICES, 'many' => 0]);
});

it('dies after advancing the cursor but before queueing the next slice: the rerun only continues the chain', function (): void {
    ($this->drainChecked)(2);
    CampaignWorld::killOn('/^select "successor_for" from "mobile_push_campaigns"/');
    $killed = $this->w->runUntilKilled();

    expect((int) CampaignWorld::row($this->campaign->id)->slices)->toBe(3)
        ->and($this->w->pending(PlanCampaignJob::class))->toBe([]);   // the chain is broken

    $this->w->rerun($killed);   // redelivered: its slice is written, it queues the next one and writes nothing
    expect((int) CampaignWorld::row($this->campaign->id)->slices)->toBe(3)
        ->and($this->w->pending(PlanCampaignJob::class))->toHaveCount(1);

    ($this->drainChecked)();
    ($this->finalChecks)();
    expect(Queue::pushed(PlanCampaignJob::class))->toHaveCount(10);   // one per slice: nothing planned twice
});

it('dies after advancing and the job is lost for good: the sweeper restarts the planner from the cursor', function (): void {
    ($this->drainChecked)(4);
    CampaignWorld::killOn('/^select "successor_for" from "mobile_push_campaigns"/');
    $this->w->runUntilKilled();

    $this->w->campaigns()->sweep();
    expect($this->w->pending(PlanCampaignJob::class))->toBe([]);   // too recent: maybe it is only slow

    $this->w->queue->advance(151);
    expect($this->w->campaigns()->sweep()['restarted'])->toBe(1);

    ($this->drainChecked)();
    ($this->finalChecks)();
    expect($this->w->requestHistogram(DEVICES))->toBe([0 => 0, 1 => DEVICES, 'many' => 0]);
});

it('a relay dies in the middle of a chunk: nothing is sent twice, what was about to leave ends unknown, the rest is sent after the lease', function (): void {
    // Plan everything first (the planner queues one relay per slice), then kill the first relay in the middle of
    // writing the outcomes of its second window (the 150th outcome written): that window was on the network.
    while ($this->w->pending(PlanCampaignJob::class) !== []) {
        $this->w->rerun($job = $this->w->pending(PlanCampaignJob::class)[0]);
        $this->w->lose($job);
        ($this->invariant)();
    }
    CampaignWorld::killOn('/^update "mobile_push_deliveries" set "state" = \?, "attempts"/', 150);
    $killed = $this->w->runUntilKilled();
    expect($killed)->toBeInstanceOf(RelayDeliveriesJob::class);
    ($this->invariant)();

    // Rows marked "about to leave" whose outcome was never recorded: the package cannot prove they were not sent.
    $inFlight = CampaignWorld::states($this->campaign->id)['sending'] ?? 0;
    expect($inFlight)->toBeGreaterThan(0)
        ->and($this->w->requestHistogram(DEVICES)['many'])->toBe(0);

    // Other relays of the same effect run now: the dead worker's leased rows are not handed out before expiry.
    ($this->drainChecked)();
    expect(CampaignWorld::states($this->campaign->id)['leased'] ?? 0)->toBeGreaterThan(0);

    $this->w->queue->advance(61);   // ledger lease (60 s) expired
    $this->w->rerun($killed);
    ($this->drainChecked)();

    ($this->finalChecks)();
    $final = CampaignWorld::states($this->campaign->id);
    expect($final['accepted'] + ($final['outcome_unknown'] ?? 0))->toBe(DEVICES)
        ->and($final['outcome_unknown'] ?? 0)->toBe($inFlight)                      // unknown, never failed, never re-sent
        ->and($this->w->requestHistogram(DEVICES)[0])->toBeLessThanOrEqual($inFlight);
});

it('a planner job delivered twice (or three times) writes its slice once and never doubles the chain', function (): void {
    $first = $this->w->pending(PlanCampaignJob::class)[0];
    $this->w->drain(1);
    $this->w->rerun($first);
    $this->w->rerun($first);
    ($this->invariant)();

    ($this->drainChecked)();
    ($this->finalChecks)();
    expect(Queue::pushed(PlanCampaignJob::class))->toHaveCount(10)
        ->and($this->w->requestHistogram(DEVICES))->toBe([0 => 0, 1 => DEVICES, 'many' => 0]);
});
