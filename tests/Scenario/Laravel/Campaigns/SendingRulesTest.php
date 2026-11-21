<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\PlanCampaignJob;
use Padosoft\MobilePush\Laravel\Sending\Gates\ConsentGate;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/CampaignWorld.php';

/*
 * The rules that apply at send time, not at planning time: consent, the shared rate limiter, planner pacing.
 */

it('opt-out after planning: the consent gate refuses some owners at send time, those are skipped and the rest sent; the campaign never knew', function (): void {
    $w = CampaignWorld::boot(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(300);
    $campaign = $w->dispatch(DeviceAudience::all());
    while ($w->pending(PlanCampaignJob::class) !== []) {   // everything planned while everyone still consents
        $w->rerun($job = $w->pending(PlanCampaignJob::class)[0]);
        $w->lose($job);
    }

    // Owners whose number is a multiple of 3 opt out now, after planning, before the relay runs.
    $w->dispatcher->gates[] = new ConsentGate(static fn (Destination $d): bool => (int) $d->ref()?->ownerId % 3 !== 0);
    $w->drain();

    $optedOut = intdiv(300 + 2, 3);
    $skippedSent = 0;
    for ($i = 0; $i < 300; $i += 3) {
        $skippedSent += $w->requestsFor($i);
    }
    $progress = $w->campaigns()->progress('default', $campaign->id);
    expect(CampaignWorld::states($campaign->id))->toBe(['accepted' => 300 - $optedOut, 'skipped' => $optedOut])
        ->and($skippedSent)->toBe(0)
        ->and($w->queue->transport->sentCount())->toBe(300 - $optedOut)
        ->and(DB::table('mobile_push_deliveries')->where('state', 'skipped')->pluck('reason_code')->unique()->all())->toBe(['policy_ineligible'])
        ->and($progress->skipped)->toBe($optedOut)
        ->and($progress->notAccepted())->toBe(0)
        // Nothing about consent is stored with the campaign.
        ->and(array_keys((array) CampaignWorld::row($campaign->id)))->not->toContain('consent');
});

it('a campaign does not starve transactional pushes: with the limiter saturated by bulk, a transactional send still goes through', function (): void {
    $w = CampaignWorld::boot(['campaigns.slice' => 100], static fn (SendKit $kit): Guards => new Guards(
        new RateLimiter($kit->guardStore, $kit->clock, 100, 0.2),   // 100 per minute, 20% reserved to transactional
        new ThrottleGate($kit->guardStore, $kit->clock),
        new AuthBreaker($kit->guardStore, $kit->clock, 5, 60),
        new DestinationQualityBreaker($kit->guardStore, $kit->clock, 200),
        $kit->clock,
    ));
    CampaignWorld::seedDevices(300);
    $campaign = $w->dispatch(DeviceAudience::all());
    $w->drain();

    $bulkSent = $w->queue->transport->sentCount();
    $states = CampaignWorld::states($campaign->id);

    $report = $w->dispatcher->send(SendKit::apnsBinding(), PushMessage::make('Your code', '123456'), [CampaignWorld::token(5_000)], SendKit::live());
    $result = SendKit::all($report)[0];

    expect($bulkSent)->toBe(80)                                   // bulk stopped at capacity minus the reserve
        ->and($states['accepted'])->toBe(80)
        ->and($states['deferred'])->toBe(220)                      // waiting for the limiter, not failed
        ->and($result->status)->toBe(SendStatus::Accepted)
        ->and($w->requestsFor(5_000))->toBe(1);
});

it('planner pacing: rate(perMinute: 50) writes slices of 50 one minute apart; a job early for its slot waits for it', function (): void {
    $w = CampaignWorld::boot(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(120);
    $campaign = $w->dispatch(DeviceAudience::all(), rate: 50);

    $w->drain(1);
    $next = $w->pending(PlanCampaignJob::class);
    expect(CampaignWorld::deliveries($campaign->id))->toBe(50)
        ->and($next)->toHaveCount(1)
        ->and($next[0]->delay)->toBe(60);

    // A queue that runs it too early (or a clock skew): nothing is written, it re-queues itself for the slot.
    $w->drain(1);
    $again = $w->pending(PlanCampaignJob::class);
    expect(CampaignWorld::deliveries($campaign->id))->toBe(50)
        ->and($again)->toHaveCount(1)
        ->and($again[0]->delay)->toBe(60)
        ->and($again[0]->early)->toBe(1);

    $w->queue->advance(60);
    $w->drain(1);
    expect(CampaignWorld::deliveries($campaign->id))->toBe(100);

    $w->queue->advance(60);
    $w->drain();
    expect(CampaignWorld::deliveries($campaign->id))->toBe(120)
        ->and($w->requestHistogram(120))->toBe([0 => 0, 1 => 120, 'many' => 0]);
});
