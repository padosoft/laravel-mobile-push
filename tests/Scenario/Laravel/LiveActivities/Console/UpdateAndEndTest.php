<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesConfig;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Console\ConsoleWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/ConsoleWorld.php';

/* A rider's activity is on the Lock Screen; the operator updates it, ends it, and the server stops after the end. */
beforeEach(function (): void {
    $this->world = ConsoleWorld::install();
    $this->activity = $this->world->activate($this->world->rider(7));
    $this->world->advance(1);
});

it('update and end: the update carries the state, a same-second update is deferred until flush, end sends event end, a later update is skipped', function (): void {
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:update', ['activity' => $this->activity->id, '--state' => '{"eta":9}']);
    $pushes = $this->world->live->pushes();

    expect($exit)->toBe(0)->and($pushes)->toHaveCount(1)
        ->and(LiveWorld::aps($pushes[0])['content-state'])->toBe(['eta' => 9])
        ->and(LiveWorld::aps($pushes[0])['event'])->toBe('update')
        ->and($output)->toContain('Outcome: sent (accepted)')->toContain('Timestamp sent: '.$this->world->live->second());

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:update', ['activity' => $this->activity->id, '--state' => '{"eta":8}']);
    expect($exit)->toBe(0)->and($output)->toContain('another update was already sent in this second')
        ->and($this->world->live->pushes())->toHaveCount(1);

    $this->world->advance(1);
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:flush');
    expect($exit)->toBe(0)->and($output)->toContain('Flushed 1')
        ->and($this->world->live->pushes())->toHaveCount(2)
        ->and(LiveWorld::aps($this->world->live->pushes()[1])['content-state'])->toBe(['eta' => 8]);

    $this->world->advance(1);
    [$exit] = $this->world->cli('mobile-push:live-activity:end', ['activity' => $this->activity->id, '--state' => '{"eta":0}', '--dismiss' => '+10 minutes']);
    $end = LiveWorld::aps($this->world->live->pushes()[2]);
    expect($exit)->toBe(0)->and($end['event'])->toBe('end')->and($end['content-state'])->toBe(['eta' => 0])
        ->and($end['dismissal-date'])->toBe($this->world->live->second() + 600)
        ->and($this->activity->fresh()->state)->toBe(ActivityState::Ended);

    $this->world->advance(5);
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:update', ['activity' => $this->activity->id, '--state' => '{"eta":1}']);
    expect($exit)->toBe(1)->and($output)->toContain('Skipped')->and($output)->toContain('ended')
        ->and($this->world->live->pushes())->toHaveCount(3);
});

it('--stale takes an ISO instant or a relative duration; the alert and low priority reach the request', function (): void {
    $this->world->cli('mobile-push:live-activity:update', [
        'activity' => $this->activity->id, '--state' => '{"eta":3}', '--stale' => '2026-11-20T11:00:00Z',
        '--title' => 'Almost there', '--low-priority' => true, '--relevance' => '0.5',
    ]);
    $request = $this->world->live->pushes()[0];
    $aps = LiveWorld::aps($request);

    expect($aps['stale-date'])->toBe(strtotime('2026-11-20T11:00:00Z'))
        ->and($aps['alert']['title'])->toBe('Almost there')
        ->and($aps['relevance-score'])->toBe(0.5)
        ->and($request->headers['apns-priority'])->toBe('5');

    $this->world->advance(1);
    $this->world->cli('mobile-push:live-activity:update', ['activity' => $this->activity->id, '--state' => '{"eta":2}', '--stale' => '+10 minutes']);
    expect(LiveWorld::aps($this->world->live->pushes()[1])['stale-date'])->toBe($this->world->live->second() + 600);
});

it('beyond the hourly high-priority allowance the output says the priority was downgraded', function (): void {
    $world = $this->world;
    config()->set('mobile-push.live_activities.high_priority.max_per_hour', 1);
    app()->forgetInstance(LiveActivitiesConfig::class);
    app()->forgetInstance(ActivityManager::class);
    $activity = $this->activity;
    $world->cli('mobile-push:live-activity:update', ['activity' => $activity->id, '--state' => '{"eta":3}']);
    $world->advance(1);

    $report = $world->json('mobile-push:live-activity:update', ['activity' => $activity->id, '--state' => '{"eta":2}']);

    expect($report['priority'])->toBe(5)->and($report['priority_downgraded'])->toBeTrue()
        ->and($world->live->pushes()[1]->headers['apns-priority'])->toBe('5');
});

it('invalid input exits 2 before any request', function (array $params, string $reason): void {
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:update', $params + ['activity' => $this->activity->id]);

    expect($exit)->toBe(2)->and($output)->toContain($reason)->and($this->world->live->transport->requests())->toBe([]);
})->with([
    'state is not valid JSON' => [['--state' => '{"eta":'], '--state is not valid JSON'],
    'state is a list' => [['--state' => '[1]'], '--state must be a JSON object'],
    'no state' => [[], '--state is required'],
    'bad stale' => [['--state' => '{}', '--stale' => 'soon'], '--stale must be an ISO-8601'],
    'body without title' => [['--state' => '{}', '--body' => 'x'], '--body needs --title'],
    'unknown activity' => [['--state' => '{}', 'activity' => 'nope'], 'No Live Activity'],
]);

it('simulate mode: update and end report what they would do, change nothing and send nothing', function (): void {
    $this->world->withOptions(SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate'));
    $before = $this->activity->fresh()->version;

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:update', ['activity' => $this->activity->id, '--state' => '{"eta":9}']);
    expect($exit)->toBe(0)->and($output)->toContain('Simulation mode')->toContain('send an update to');
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:end', ['activity' => $this->activity->id]);
    expect($exit)->toBe(0)->and($output)->toContain('Simulation mode');
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:flush');

    expect($exit)->toBe(0)->and($output)->toContain('Simulation mode')
        ->and($this->world->live->transport->requests())->toBe([])
        ->and($this->activity->fresh()->version)->toBe($before);
});
