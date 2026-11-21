<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCompleted;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignPlanned;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignStarted;
use Padosoft\MobilePush\Laravel\Campaigns\PlanCampaignJob;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;

require_once __DIR__.'/CampaignWorld.php';

/*
 * "Black Friday to the whole user base": every registered device, planned in slices of 1000, one job per slice, sent
 * by the relay on the bulk queue. The oracle is the transport (one request per token) and the ledger table.
 */

function blackFriday(int $devices, int $memoryCeilingMb): void
{
    Event::fake([CampaignStarted::class, CampaignPlanned::class, CampaignCompleted::class]);
    $w = CampaignWorld::boot();
    CampaignWorld::seedDevices($devices);

    $campaign = $w->dispatch(DeviceAudience::all());
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $jobs = $w->drain();

    $progress = $w->campaigns()->progress('default', $campaign->id);
    $reconcile = $w->campaigns()->reconcile('default', $campaign->id);
    $slices = (int) ceil($devices / 1000);

    expect($w->requestHistogram($devices))->toBe([0 => 0, 1 => $devices, 'many' => 0])
        ->and($w->requestsOutside($devices))->toBe(0)
        ->and(CampaignWorld::deviceCoverage($campaign->id))->toBe(['devices' => $devices, 'rows' => $devices])
        ->and(CampaignWorld::states($campaign->id))->toBe(['accepted' => $devices])
        ->and($progress->campaign->status)->toBe(CampaignStatus::Completed)
        ->and($progress->campaign->planned)->toBe($devices)
        ->and($progress->campaign->slices)->toBe($slices)
        ->and($progress->accepted)->toBe($devices)
        ->and($progress->unknown)->toBe(0)
        ->and($progress->percent())->toBe(100.0)
        ->and($progress->recipientsKnown)->toBeTrue()
        ->and($reconcile->clean())->toBeTrue()
        ->and(($w->peakMemory() - $before) / 1048576)->toBeLessThan($memoryCeilingMb);

    // One planner job per slice (none planned twice) and every job on the bulk queue.
    expect(Queue::pushed(PlanCampaignJob::class))->toHaveCount($slices)
        ->and(Queue::pushed(PlanCampaignJob::class)->pluck('queue')->unique()->all())->toBe(['push-bulk'])
        ->and(Queue::pushed(RelayDeliveriesJob::class)->pluck('queue')->unique()->all())->toBe(['push-bulk'])
        ->and($jobs)->toBeGreaterThanOrEqual($slices * 2);

    Event::assertDispatchedTimes(CampaignStarted::class, 1);
    Event::assertDispatchedTimes(CampaignPlanned::class, 1);
    Event::assertDispatched(CampaignCompleted::class, static fn (CampaignCompleted $e): bool => $e->campaignId === $campaign->id
        && $e->planned === $devices && $e->accepted === $devices && $e->unknown === 0 && $e->notAccepted === 0);
}

it('plans 20,000 registered devices in slices, sends each exactly once and ends completed with a clean reconcile', function (): void {
    blackFriday(20_000, 32);
});

it('plans 500,000 registered devices with flat memory', function (): void {
    blackFriday(500_000, 48);
})->group('slow');
