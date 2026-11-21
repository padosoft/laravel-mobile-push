<?php

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\ConsoleCommands;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Console\ConsoleWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/ConsoleWorld.php';

$commands = [
    ['mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => 'x:1', '--title' => 't']],
    ['mobile-push:live-activity:update', ['activity' => 'x', '--state' => '{}']],
    ['mobile-push:live-activity:end', ['activity' => 'x']],
    ['mobile-push:live-activity:list', []],
    ['mobile-push:live-activity:flush', []],
    ['mobile-push:live-activity:prune', []],
    ['mobile-push:channels:list', []],
    ['mobile-push:channels:create', []],
    ['mobile-push:channels:delete', ['channel' => 'x', '--force' => true]],
    ['mobile-push:channels:sync', []],
    ['mobile-push:channels:publish', ['channel' => 'x', '--state' => '{}']],
];

it('module disabled: every command exits 1 with a clear message and touches nothing', function (string $command, array $params): void {
    $world = ConsoleWorld::install();
    config()->set('mobile-push.live_activities.enabled', false);

    [$exit, $output] = $world->cli($command, $params);

    expect($exit)->toBe(1)->and($output)->toContain('Live Activities are disabled')->toContain('mobile-push.live_activities.enabled')
        ->and($world->live->transport->requests())->toBe([]);
})->with($commands);

it('module disabled with --json still answers in JSON', function (): void {
    $world = ConsoleWorld::install();
    config()->set('mobile-push.live_activities.enabled', false);

    $report = $world->json('mobile-push:live-activity:list');

    expect($report['ok'])->toBeFalse()->and($report['error'])->toContain('disabled');
});

it('the module registers the eleven commands only when it is enabled', function (): void {
    config()->set('mobile-push.live_activities.enabled', true);
    LiveActivitiesModule::boot($this->app);
    $names = array_keys((new ConsoleApplication($this->app, $this->app['events'], '1.0'))->all());

    expect($names)->toContain('mobile-push:live-activity:start', 'mobile-push:channels:publish')
        ->and(count(ConsoleCommands::all()))->toBe(11);

    config()->set('mobile-push.live_activities.enabled', false);
    $names = array_keys((new ConsoleApplication($this->app, $this->app['events'], '1.0'))->all());
    expect($names)->not->toContain('mobile-push:live-activity:start');
});

it('canary: a whole session of commands, human and JSON, never prints a clear token', function (): void {
    $world = ConsoleWorld::install();
    $rider = $world->rider(7);
    $activity = $world->activate($rider);
    $world->advance(1);
    $owner = ConsoleWorld::RIDER.':7';

    $world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $owner, '--title' => 'Hi']);
    $world->json('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $owner, '--title' => 'Hi']);
    $world->cli('mobile-push:live-activity:list');
    $world->json('mobile-push:live-activity:list');
    $world->cli('mobile-push:live-activity:update', ['activity' => $activity->id, '--state' => '{"eta":1}']);
    $world->cli('mobile-push:live-activity:update', ['activity' => $activity->id, '--state' => '{"eta":2}']);
    $world->advance(1);
    $world->cli('mobile-push:live-activity:flush');
    $world->json('mobile-push:live-activity:end', ['activity' => $activity->id]);
    $world->cli('mobile-push:live-activity:update', ['activity' => $activity->id, '--state' => '{}']);
    $world->cli('mobile-push:live-activity:prune', ['--pretend' => true]);
    $world->cli('mobile-push:live-activity:update', ['activity' => $activity->id, '--state' => 'broken']);

    $all = implode("\n", ConsoleWorld::$outputs);
    expect($world->secrets)->toHaveCount(2);
    foreach ($world->secrets as $secret) {
        expect($all)->not->toContain($secret)->and(strtolower($all))->not->toContain($secret);
    }
    expect($all)->toContain(TokenMasker::mask(LiveWorld::activityToken('a1')->value()));
});
