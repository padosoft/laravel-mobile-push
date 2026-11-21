<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCancelled;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;

require_once __DIR__.'/CampaignWorld.php';

/*
 * "An admin action cancels a campaign inside the application's own transaction, and another request paused it a
 * moment before." Server engines only (the MySQL / PostgreSQL CI jobs): the other request is a real second
 * connection that commits while the first one's transaction is open.
 *
 * At MySQL's default REPEATABLE READ the open transaction keeps reading the campaign as it was at its first read. The
 * cancel loses its compare-and-set (the version moved on) and must then see the campaign as committed, or it would
 * lose every further round and give up.
 */
beforeEach(function (): void {
    $default = (string) config('database.default');
    if (! in_array(config("database.connections.{$default}.driver"), ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Needs a server database: two connections write at once.');
    }
    config()->set('database.connections.other_request', config("database.connections.{$default}"));
    Event::fake([CampaignCancelled::class]);
    $this->w = CampaignWorld::boot(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(300);
    $this->id = $this->w->dispatch(DeviceAudience::all())->id;
    $this->w->drain(1);   // one slice planned: 100 deliveries exist
});

afterEach(function (): void {
    DB::purge('other_request');
});

it('cancels a campaign that another request paused after this transaction first read it', function (): void {
    $id = $this->id;
    $campaigns = $this->w->campaigns();

    $cancelled = DB::transaction(function () use ($campaigns, $id) {
        // The application looks at the campaign first: this read is what its transaction keeps seeing.
        $seen = $campaigns->find('default', $id);
        expect($seen?->status)->toBe(CampaignStatus::Planning);

        // Another request pauses it and commits: status and version move on.
        $changed = DB::connection('other_request')->table('mobile_push_campaigns')->where('id', $id)
            ->update(['status' => CampaignStatus::Paused->value, 'version' => $seen->version + 1]);
        expect($changed)->toBe(1);

        return $campaigns->cancel('default', $id);
    });

    $row = DB::connection('other_request')->table('mobile_push_campaigns')->where('id', $id)->first();
    expect($cancelled->status)->toBe(CampaignStatus::Cancelled)
        ->and($row->status)->toBe(CampaignStatus::Cancelled->value)
        ->and((int) $row->version)->toBe($cancelled->version);
    Event::assertDispatched(CampaignCancelled::class, 1);
});
