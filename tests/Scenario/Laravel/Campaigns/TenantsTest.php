<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignNotFoundException;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/CampaignWorld.php';

/*
 * Two tenants with the same campaign name and the same device tokens: nothing mixes, controls stay in their scope.
 */

it('two tenants: same name, same tokens, separate campaigns; ids from the other scope are not found and controls never cross', function (): void {
    $w = CampaignWorld::boot(['campaigns.slice' => 50]);
    CampaignWorld::seedDevices(100);
    CampaignWorld::seedDevices(100, 0, SendKit::apnsBinding(scope: 'tenant-b'));   // the very same token values

    $a = $w->dispatch(DeviceAudience::all(), scope: 'default', name: 'spring-sale');
    $b = $w->dispatch(DeviceAudience::all(), scope: 'tenant-b', name: 'spring-sale');
    $w->drain(2);   // the first slice of each

    expect($w->campaigns()->find('tenant-b', $a->id))->toBeNull()
        ->and($w->campaigns()->find('default', $b->id))->toBeNull()
        ->and(fn () => $w->campaigns()->pause('tenant-b', $a->id))->toThrow(CampaignNotFoundException::class)
        ->and(fn () => $w->campaigns()->cancel('default', $b->id))->toThrow(CampaignNotFoundException::class)
        ->and(fn () => $w->campaigns()->progress('tenant-b', $a->id))->toThrow(CampaignNotFoundException::class);

    $w->campaigns()->pause('default', $a->id);
    $w->drain();
    $w->campaigns()->sweep();

    $progressA = $w->campaigns()->progress('default', $a->id);
    $progressB = $w->campaigns()->progress('tenant-b', $b->id);
    $deviceScopes = DB::table('mobile_push_devices')->pluck('scope', 'id')->mapWithKeys(static fn ($scope, $id): array => [(string) $id => $scope])->all();
    expect($progressA->campaign->status)->toBe(CampaignStatus::Paused)
        ->and($progressA->total())->toBe(50)
        ->and($progressB->campaign->status)->toBe(CampaignStatus::Completed)
        ->and($progressB->accepted)->toBe(100)
        // Every delivery row is in the scope of its campaign, and points at a device of that scope.
        ->and(DB::table('mobile_push_deliveries')->where('campaign_id', $a->id)->where('scope', '!=', 'default')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->where('campaign_id', $b->id)->where('scope', '!=', 'tenant-b')->count())->toBe(0)
        // Compared in PHP: device_id is a string column (it also holds ids of other registries), devices.id an integer.
        ->and(DB::table('mobile_push_deliveries')->get(['device_id', 'scope'])
            ->filter(static fn (object $d): bool => ($deviceScopes[(string) $d->device_id] ?? null) !== $d->scope)->count())->toBe(0)
        ->and($w->queue->transport->sentCount())->toBe(150);
});
