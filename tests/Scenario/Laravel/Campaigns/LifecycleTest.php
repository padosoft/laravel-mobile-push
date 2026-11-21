<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DestinationListAudience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCompleted;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignStarted;
use Padosoft\MobilePush\Laravel\Campaigns\PlanCampaignJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/CampaignWorld.php';

/*
 * Creating, finishing and pruning a campaign; the profile it needs.
 */

it('light profile: dispatching a campaign throws a ConfigurationException saying campaigns need the durable profile', function (): void {
    $w = CampaignWorld::boot();
    config()->set('mobile-push.delivery.profile', 'light');

    expect(fn () => $w->dispatch(DeviceAudience::all()))
        ->toThrow(ConfigurationException::class, 'Campaigns need the durable delivery profile')
        ->and(DB::table('mobile_push_campaigns')->count())->toBe(0)
        ->and(DB::table('mobile_push_effects')->count())->toBe(0)
        ->and(Queue::pushed(PlanCampaignJob::class))->toHaveCount(0);
});

it('campaigns disabled: a clear ConfigurationException, nothing written', function (): void {
    $w = CampaignWorld::boot(['campaigns.enabled' => false]);

    expect(fn () => $w->dispatch(DeviceAudience::all()))->toThrow(ConfigurationException::class, 'mobile-push.campaigns.enabled')
        ->and(DB::table('mobile_push_effects')->count())->toBe(0);
});

it('finished: completed once, CampaignCompleted carries the counters, unknown outcomes reported apart from failures', function (): void {
    Event::fake([CampaignCompleted::class]);
    $w = CampaignWorld::boot(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(200);
    // Tokens 0-9: the response is lost after the request was written. Tokens 10-14: refused (BadDeviceToken).
    $w->answer(static function (Request $r) {
        $n = (int) hexdec(substr(SendKit::tokenOf($r), -12)) - 1;

        return match (true) {
            $n < 10 => new TransportFailure(TransportEvidence::Unknown, 56, 'connection reset after write', true),
            $n < 15 => SendKit::apnsReason(400, 'BadDeviceToken'),
            default => null,
        };
    });

    $campaign = $w->dispatch(DeviceAudience::all());
    $w->drain();
    $first = $w->campaigns()->progress('default', $campaign->id);
    $w->campaigns()->progress('default', $campaign->id);
    $w->campaigns()->sweep();

    expect($first->campaign->status)->toBe(CampaignStatus::Completed)
        ->and($first->unknown)->toBe(10)
        ->and($first->rejected)->toBe(5)
        ->and($first->notAccepted())->toBe(5)          // unknown is not a failure
        ->and($first->accepted)->toBe(185)
        ->and($first->estimate?->remainingSeconds)->toBe(0)
        ->and($first->campaign->finalCounts['outcome_unknown'] ?? null)->toBe(10);

    Event::assertDispatchedTimes(CampaignCompleted::class, 1);
    Event::assertDispatched(CampaignCompleted::class, static fn (CampaignCompleted $e): bool => $e->planned === 200
        && $e->accepted === 185 && $e->unknown === 10 && $e->notAccepted === 5 && $e->counts['rejected'] === 5);
});

it('an empty audience completes right after planning', function (): void {
    Event::fake([CampaignCompleted::class]);
    $w = CampaignWorld::boot();
    $campaign = $w->dispatch(DeviceAudience::all());
    $w->drain();

    expect($w->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Completed);
    Event::assertDispatchedTimes(CampaignCompleted::class, 1);
});

it('a draft plans nothing until started; the upper bound is fixed at the start, not at the draft', function (): void {
    Event::fake([CampaignStarted::class]);
    $w = CampaignWorld::boot();
    CampaignWorld::seedDevices(10);
    $draft = $w->campaigns()->campaign(CampaignWorld::message())->name('later')->via('ios')->audience(DeviceAudience::all())->draft();
    CampaignWorld::seedDevices(5, 10);
    $w->drain();
    expect(CampaignWorld::deliveries($draft->id))->toBe(0)
        ->and($draft->status)->toBe(CampaignStatus::Draft);

    $started = $w->campaigns()->start('default', $draft->id);
    $w->campaigns()->start('default', $draft->id);   // idempotent: no second chain
    $w->drain();

    expect($started->upperBound)->toBe('id:15')
        ->and(CampaignWorld::deliveries($draft->id))->toBe(15)
        ->and(Queue::pushed(PlanCampaignJob::class))->toHaveCount(1);
    Event::assertDispatchedTimes(CampaignStarted::class, 1);
});

it('the message lives encrypted in the ledger only; the campaign row holds no token and no body', function (): void {
    $w = CampaignWorld::boot();
    $campaign = $w->dispatch(DestinationListAudience::of([CampaignWorld::token(1)]));
    $row = json_encode(CampaignWorld::row($campaign->id));

    expect($row)->not->toContain(CampaignWorld::token(1)->value())
        ->and($row)->not->toContain('Everything -40% today')
        ->and(CampaignWorld::row($campaign->id)->title)->toBe('Black Friday')
        ->and(CampaignWorld::row($campaign->id)->traffic_class)->toBe('bulk');
});

it('prunes finished campaigns after the retention, never running ones', function (): void {
    $w = CampaignWorld::boot(['campaigns.retention_days' => 7]);
    $done = $w->dispatch(DeviceAudience::all(), name: 'done');
    $w->drain();
    $paused = $w->campaigns()->campaign(CampaignWorld::message())->via('ios')->audience(DeviceAudience::all())->draft();

    $w->queue->advance(6 * 86_400);
    expect($w->campaigns()->prune())->toBe(0);
    $w->queue->advance(2 * 86_400);
    expect($w->campaigns()->prune())->toBe(1)
        ->and($w->campaigns()->find('default', $done->id))->toBeNull()
        ->and($w->campaigns()->find('default', $paused->id))->not->toBeNull();
});

it('a failed campaign is resumable, so prune keeps it; the prune count is exactly what prune deletes; the list is scoped', function (): void {
    $w = CampaignWorld::boot(['campaigns.retention_days' => 7]);
    CampaignWorld::seedDevices(3);
    $done = $w->dispatch(DeviceAudience::all(), name: 'done');
    $w->drain();
    $w->queue->advance(1);
    $cancelled = $w->dispatch(DeviceAudience::all(), name: 'cancelled');
    $w->campaigns()->cancel('default', $cancelled->id);
    $w->queue->advance(1);
    $failing = $w->dispatch(DeviceAudience::all(), name: 'failing');
    // The message snapshot became unreadable (erased): planning stops with a permanent error, resumable once fixed.
    DB::table('mobile_push_effects')->where('id', $failing->effectId)->update(['message' => null]);
    $w->drain();
    $other = $w->dispatch(DeviceAudience::all(), scope: 'tenant-b', name: 'other tenant');
    $w->campaigns()->cancel('tenant-b', $other->id);

    expect($w->campaigns()->find('default', $failing->id)?->status)->toBe(CampaignStatus::Failed)
        ->and(array_map(static fn ($c): string => $c->name, $w->campaigns()->list('default')))->toBe(['failing', 'cancelled', 'done'])
        ->and(array_map(static fn ($c): string => $c->name, $w->campaigns()->list('default', CampaignStatus::Failed)))->toBe(['failing'])
        ->and($w->campaigns()->list('default', limit: 1))->toHaveCount(1)
        ->and(array_map(static fn ($c): string => $c->name, $w->campaigns()->list('tenant-b')))->toBe(['other tenant']);

    $w->queue->advance(365 * 86_400);
    $prunable = $w->campaigns()->countPrunable();
    expect($prunable)->toBe(3)                                          // done + cancelled + the other tenant's
        ->and($w->campaigns()->countPrunable(scope: 'tenant-b'))->toBe(1)
        ->and($w->campaigns()->prune())->toBe($prunable)
        ->and($w->campaigns()->find('default', $failing->id))->not->toBeNull()
        ->and($w->campaigns()->countPrunable())->toBe(0);
});
