<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\Console\CampaignConsole;

require_once __DIR__.'/CampaignConsole.php';

beforeEach(function (): void {
    $this->c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
});

it('reconcile: consistent exits 0; a deliberately broken counter exits 1 and prints the difference', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain();

    [$exit, $output] = $c->cli('mobile-push:campaign:reconcile', ['campaign' => $campaign->id]);
    expect($exit)->toBe(0)->and($output)->toContain('Clean: 200 unique destinations planned, 200 deliveries');

    DB::table('mobile_push_campaigns')->where('id', $campaign->id)->update(['planned' => 195]);

    [$exit, $output] = $c->cli('mobile-push:campaign:reconcile', ['campaign' => $campaign->id]);
    $json = $c->json('mobile-push:campaign:reconcile', ['campaign' => $campaign->id]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Difference: planned 195, ledger 200')->toContain('Difference (ledger - planned): 5')->toContain('Nothing was corrected')
        ->and($json['ok'])->toBeFalse()->and($json['difference'])->toBe(5)->and($json['planned'])->toBe(195)->and($json['ledger'])->toBe(200)
        ->and((int) CampaignWorld::row($campaign->id)->planned)->toBe(195);   // reported, never corrected
});

it('sweep completes a finished campaign nobody looked at', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain();
    expect(CampaignWorld::row($campaign->id)->status)->toBe('running');

    [$exit, $output] = $c->cli('mobile-push:campaign:sweep');
    $json = $c->json('mobile-push:campaign:sweep');

    expect($exit)->toBe(0)->and($output)->toContain('completed 1 finished campaign')
        ->and(CampaignWorld::row($campaign->id)->status)->toBe('completed')
        ->and($json)->toBe(['ok' => true, 'restarted' => 0, 'completed' => 0]);
});

it('sweep restarts a planner chain that lost its job', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(1);
    foreach ($c->world->pending() as $job) {
        $c->world->lose($job);   // the worker died with the queued second slice
    }
    $c->world->queue->advance(600);

    [$exit, $output] = $c->cli('mobile-push:campaign:sweep');
    $c->world->drain();

    expect($exit)->toBe(0)->and($output)->toContain('Restarted 1 stalled planner chain')
        ->and(CampaignWorld::deliveries($campaign->id))->toBe(200);
});

it('prune removes only campaigns older than the retention; --pretend removes nothing', function (): void {
    $c = $this->c;
    $old = $c->world->dispatch(DeviceAudience::all(), name: 'old');
    $c->world->drain();
    $c->world->campaigns()->progress('default', $old->id);
    $c->world->queue->advance(40 * 86_400);
    $recent = $c->world->dispatch(DeviceAudience::all(), name: 'recent');
    $c->world->drain();
    $c->world->campaigns()->progress('default', $recent->id);
    $running = $c->world->dispatch(DeviceAudience::all(), name: 'running');   // not finished: never pruned

    [$exit, $output] = $c->cli('mobile-push:campaign:prune', ['--days' => '30', '--pretend' => true]);
    $json = $c->json('mobile-push:campaign:prune', ['--days' => '30', '--pretend' => true]);
    expect($exit)->toBe(0)->and($output)->toContain('Would delete 1 finished campaign')->toContain('nothing was changed')
        ->and($json['would_delete'])->toBe(1)
        ->and(DB::table('mobile_push_campaigns')->count())->toBe(3);

    [$exit, $output] = $c->cli('mobile-push:campaign:prune', ['--days' => '30']);
    expect($exit)->toBe(0)->and($output)->toContain('Deleted 1 finished campaign')
        ->and($c->world->campaigns()->find('default', $old->id))->toBeNull()
        ->and($c->world->campaigns()->find('default', $recent->id))->not->toBeNull()
        ->and($c->world->campaigns()->find('default', $running->id))->not->toBeNull();

    [$exit, $output] = $c->cli('mobile-push:campaign:prune', ['--days' => '0']);
    expect($exit)->toBe(2)->and($output)->toContain('--days');
});

it('list: newest first, filtered by status and scope, JSON the same', function (): void {
    $c = $this->c;
    $first = $c->world->dispatch(DeviceAudience::all(), name: 'spring');
    $c->world->drain();
    $c->world->campaigns()->progress('default', $first->id);
    $c->world->queue->advance(5);
    $second = $c->world->dispatch(DeviceAudience::all(), name: 'summer');
    $other = $c->world->dispatch(DeviceAudience::all(), scope: 'tenant-b', name: 'tenant-b-sale');

    [$exit, $output] = $c->cli('mobile-push:campaign:list');
    $json = $c->json('mobile-push:campaign:list');
    $completed = $c->json('mobile-push:campaign:list', ['--status' => 'completed']);
    $tenantB = $c->json('mobile-push:campaign:list', ['--scope' => 'tenant-b']);

    expect($exit)->toBe(0)
        ->and(strpos($output, 'summer'))->toBeLessThan(strpos($output, 'spring'))
        ->and($output)->not->toContain('tenant-b-sale')->toContain('ios')
        ->and(array_column($json['campaigns'], 'id'))->toBe([$second->id, $first->id])
        ->and(array_column($completed['campaigns'], 'id'))->toBe([$first->id])
        ->and(array_column($tenantB['campaigns'], 'id'))->toBe([$other->id]);

    [$exit, $output] = $c->cli('mobile-push:campaign:list', ['--status' => 'nonsense']);
    expect($exit)->toBe(2)->and($output)->toContain('--status must be one of');
    [$exit] = $c->cli('mobile-push:campaign:list', ['--limit' => '0']);
    expect($exit)->toBe(2);
});
