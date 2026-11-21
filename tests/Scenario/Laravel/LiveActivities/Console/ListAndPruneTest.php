<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\ClientActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Console\ConsoleWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/ConsoleWorld.php';

beforeEach(function (): void {
    $this->world = ConsoleWorld::install();
    $this->activity = $this->world->activate($this->world->rider(7));
});

it('list shows states, expiry, client report and a masked token, never a clear one', function (): void {
    $this->activity->forceFill(['client_reported_state' => ClientActivityState::Stale])->save();
    $clear = LiveWorld::activityToken('a1')->value();

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:list');
    expect($exit)->toBe(0)->and($output)->toContain($this->activity->id)->toContain('delivery')->toContain('active')
        ->toContain('stale')->toContain(TokenMasker::mask($clear))->not->toContain($clear);

    $json = $this->world->json('mobile-push:live-activity:list', ['--state' => 'active', '--type' => 'delivery', '--owner' => ConsoleWorld::RIDER.':7']);
    expect($json['count'])->toBe(1)
        ->and($json['activities'][0]['update_token'])->toBe(TokenMasker::mask($clear))
        ->and($json['activities'][0]['client_reported_state'])->toBe('stale')
        ->and($json['activities'][0]['end_accepted_at'])->toBeNull();

    expect($this->world->json('mobile-push:live-activity:list', ['--state' => 'ended'])['count'])->toBe(0);
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:list', ['--state' => 'bogus']);
    expect($exit)->toBe(2)->and($output)->toContain('--state must be one of');
    [$exit] = $this->world->cli('mobile-push:live-activity:list', ['--limit' => '0']);
    expect($exit)->toBe(2);
});

it('prune expires an activity past its local expiry; --pretend counts and changes nothing', function (): void {
    $this->world->advance(9 * 3600);

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:prune', ['--pretend' => true]);
    expect($exit)->toBe(0)->and($output)->toContain('Would expire 1')->toContain('nothing was changed')
        ->and($this->activity->fresh()->state)->toBe(ActivityState::Active);

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:prune');
    expect($exit)->toBe(0)->and($output)->toContain('Expired 1')
        ->and($this->activity->fresh()->state)->toBe(ActivityState::Expired);

    $this->world->advance(8 * 86400);
    $pretend = $this->world->json('mobile-push:live-activity:prune', ['--pretend' => true, '--days' => '7']);
    expect($pretend['deleted'])->toBe(1)->and(PushActivity::blank()->newQuery()->count())->toBe(1);

    $real = $this->world->json('mobile-push:live-activity:prune', ['--days' => '7']);
    expect($real['deleted'])->toBe(1)->and(PushActivity::blank()->newQuery()->count())->toBe(0)
        ->and($this->world->live->transport->requests())->toBe([]);
});
