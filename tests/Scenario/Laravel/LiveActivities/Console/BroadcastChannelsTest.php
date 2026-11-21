<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelRegistry;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesConfig;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Console\ConsoleWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Symfony\Component\Console\Exception\InvalidOptionException;

require_once __DIR__.'/ConsoleWorld.php';

const CH_A = ConsoleWorld::CHANNEL_A;
const CH_B = ConsoleWorld::CHANNEL_B;

beforeEach(function (): void {
    $this->world = ConsoleWorld::install();
});

function lostCreate(): TransportFailure
{
    return new TransportFailure(TransportEvidence::Unknown, 92, 'stream reset after the request was written');
}

it('create, list, publish and delete a channel: management host, broadcast route, one publication', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];

    [$exit, $output] = $w->cli('mobile-push:channels:create', ['--label' => 'Derby', '--storage' => 'recent']);
    expect($exit)->toBe(0)->and($output)->toContain(CH_A)->toContain('of the local cap 9500')->toContain('10,000');
    $create = $w->live->managementRequests()[0];
    expect($create->url)->toContain('api-manage-broadcast')
        ->and(json_decode($create->body, true))->toBe(['message-storage-policy' => 1, 'push-type' => 'LiveActivity']);

    $list = $w->json('mobile-push:channels:list');
    expect($list['channels'][0]['channel_id'])->toBe(CH_A)->and($list['channels'][0]['state'])->toBe('active')
        ->and($list['cap']['count'])->toBe(1)->and($list['cap']['apple_limit'])->toBe(10000);

    $w->advance(1);
    [$exit, $output] = $w->cli('mobile-push:channels:publish', ['channel' => CH_A, '--state' => '{"score":"1-0"}']);
    $push = $w->live->pushes()[0];
    expect($exit)->toBe(0)->and($output)->toContain('1 publication')->toContain('not N deliveries')
        ->and($push->url)->toBe('https://api.push.apple.com/4/broadcasts/apps/'.SendKit::BUNDLE)
        ->and($push->headers['apns-channel-id'])->toBe(CH_A)
        ->and(LiveWorld::aps($push)['event'])->toBe('update');

    $w->advance(1);
    $report = $w->json('mobile-push:channels:publish', ['channel' => CH_A, '--end' => true, '--state' => '{"score":"2-1"}', '--dismiss' => '+5 minutes']);
    expect($report['publications'])->toBe(1)->and(LiveWorld::aps($w->live->pushes()[1])['event'])->toBe('end');

    $w->live->channelAnswers = [new Response(204)];
    [$exit, $output] = $w->cli('mobile-push:channels:delete', ['channel' => CH_A, '--force' => true]);
    $delete = $w->live->managementRequests()[1];
    expect($exit)->toBe(0)->and($delete->method)->toBe('DELETE')->and($delete->headers['apns-channel-id'])->toBe(CH_A)
        ->and(BroadcastChannel::blank()->newQuery()->first()->state)->toBe(ChannelState::Deleted);
});

it('there is no way to publish a start: the option does not exist, and nothing is sent', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    $w->cli('mobile-push:channels:create');

    expect(fn () => $w->cli('mobile-push:channels:publish', ['channel' => CH_A, '--event' => 'start', '--state' => '{}']))->toThrow(InvalidOptionException::class);
    expect($w->live->pushes())->toBe([]);

    [$exit, $output] = $w->cli('mobile-push:channels:publish', ['channel' => CH_A]);
    expect($exit)->toBe(2)->and($output)->toContain('--state is required');
    [$exit] = $w->cli('mobile-push:channels:publish', ['channel' => CH_A, '--state' => '{}', '--dismiss' => '+1 hour']);
    expect($exit)->toBe(2);
});

it('delete asks first: declined (or non-interactive) deletes nothing, and a missing channel exits 2', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    $w->cli('mobile-push:channels:create');

    [$exit, $output] = $w->cli('mobile-push:channels:delete', ['channel' => CH_A, '--no-interaction' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('Aborted')->and($w->live->managementRequests())->toHaveCount(1);

    [$exit] = $w->cli('mobile-push:channels:delete', ['channel' => 'nope', '--force' => true]);
    expect($exit)->toBe(2);
});

it('the create response was lost: the row is unknown, nothing is retried, sync adopts the single orphan', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [lostCreate()];

    [$exit, $output] = $w->cli('mobile-push:channels:create');
    expect($exit)->toBe(1)->and($output)->toContain('NOT retry blindly')->toContain('mobile-push:channels:sync')
        ->and($w->live->managementRequests())->toHaveCount(1)
        ->and(BroadcastChannel::blank()->newQuery()->first()->state)->toBe(ChannelState::Unknown);

    [, $output] = $w->cli('mobile-push:channels:list');
    expect($output)->toContain('unknown')->toContain('channels:sync');

    $w->live->channelAnswers = [$w->channelList(CH_A)];
    [$exit, $output] = $w->cli('mobile-push:channels:sync');
    expect($exit)->toBe(0)->and($output)->toContain('Adopted')->toContain(CH_A)
        ->and(BroadcastChannel::blank()->newQuery()->first()->channel_id)->toBe(CH_A)
        ->and($w->live->managementRequests())->toHaveCount(2);
});

it('with two orphans sync lists them and asks for --adopt; --adopt resolves one', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [lostCreate(), lostCreate()];
    $w->cli('mobile-push:channels:create');
    $w->cli('mobile-push:channels:create');
    $rows = BroadcastChannel::blank()->newQuery()->orderBy('created_at')->get();

    $w->live->channelAnswers = [$w->channelList(CH_A, CH_B)];
    [$exit, $output] = $w->cli('mobile-push:channels:sync');
    expect($exit)->toBe(1)->and($output)->toContain('orphan: '.CH_A)->toContain('orphan: '.CH_B)->toContain('--adopt=');

    $w->live->channelAnswers = [$w->channelList(CH_A, CH_B)];
    [$exit, $output] = $w->cli('mobile-push:channels:sync', ['--adopt' => CH_A]);
    expect($exit)->toBe(1)->and($output)->toContain('say which one');

    $w->live->channelAnswers = [$w->channelList(CH_A, CH_B)];
    $report = $w->json('mobile-push:channels:sync', ['--adopt' => CH_B.':'.$rows[1]->id]);
    expect($report['adopted'])->toBe([CH_B])->and($report['orphans'])->toBe([CH_A])
        ->and($rows[1]->fresh()->state)->toBe(ChannelState::Active);

    $w->live->channelAnswers = [$w->channelList(CH_A, CH_B)];
    [, $output] = $w->cli('mobile-push:channels:sync', ['--adopt' => 'bogus']);
    expect($output)->toContain('is not an orphan');
});

it('sync reconciles both ways: a local channel APNs no longer lists is marked deleted', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    $w->cli('mobile-push:channels:create');

    $w->live->channelAnswers = [$w->channelList()];
    $report = $w->json('mobile-push:channels:sync');

    expect($report['marked_deleted'])->toBe([CH_A])->and($report['ok'])->toBeTrue()
        ->and(BroadcastChannel::blank()->newQuery()->first()->state)->toBe(ChannelState::Deleted);
});

it('sync --cleanup deletes only unreferenced idle channels, after confirmation', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    $w->cli('mobile-push:channels:create');
    $w->advance(2 * 86400);

    $w->live->channelAnswers = [$w->channelList(CH_A)];
    $this->artisan('mobile-push:channels:sync', ['--cleanup' => true])
        ->expectsConfirmation('Delete 1 channel(s) with no open activity ('.CH_A.')? This is irreversible.', 'no')
        ->assertExitCode(0);
    expect(BroadcastChannel::blank()->newQuery()->first()->state)->toBe(ChannelState::Active);

    $w->live->channelAnswers = [$w->channelList(CH_A), new Response(204)];
    [$exit, $output] = $w->cli('mobile-push:channels:sync', ['--cleanup' => true, '--force' => true]);
    expect($exit)->toBe(0)->and($output)->toContain('Deleted unreferenced channel: '.CH_A)
        ->and(BroadcastChannel::blank()->newQuery()->first()->state)->toBe(ChannelState::Deleted);
});

it('simulate mode: every channel command reports what it would do and makes no request', function (): void {
    $w = $this->world;
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    $w->cli('mobile-push:channels:create');
    $before = count($w->live->transport->requests());
    $w->withOptions(SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate'));

    foreach ([
        ['mobile-push:channels:create', []],
        ['mobile-push:channels:delete', ['channel' => CH_A, '--force' => true]],
        ['mobile-push:channels:sync', []],
        ['mobile-push:channels:publish', ['channel' => CH_A, '--state' => '{"a":1}']],
    ] as [$command, $params]) {
        [$exit, $output] = $w->cli($command, $params);
        expect($exit)->toBe(0)->and($output)->toContain('Simulation mode');
    }

    expect($w->live->transport->requests())->toHaveCount($before)
        ->and(BroadcastChannel::blank()->newQuery()->count())->toBe(1);
    [$exit] = $w->cli('mobile-push:channels:list');
    expect($exit)->toBe(0);
});

it('channel management outside production is blocked with the remedy until it is authorised for the connection', function (): void {
    $w = $this->world;
    $w->withOptions(SendKit::live(applicationEnvironment: 'staging', live: new LiveSettings(allow: true)));

    [$exit, $output] = $w->cli('mobile-push:channels:create');
    expect($exit)->toBe(1)->and($output)->toContain('Blocked by safe mode')->toContain('live.allow_channel_management')->toContain('Remedy')
        ->and($w->live->transport->requests())->toBe([])
        ->and(BroadcastChannel::blank()->newQuery()->count())->toBe(0);

    $w->withOptions(SendKit::live(applicationEnvironment: 'staging', live: new LiveSettings(allow: true, allowChannelManagement: true)));
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    [$exit] = $w->cli('mobile-push:channels:create');
    expect($exit)->toBe(0)->and($w->live->managementRequests())->toHaveCount(1);
});

it('the local cap is shown and enforced before the network', function (): void {
    $w = $this->world;
    config()->set('mobile-push.live_activities.channels.max_per_environment', 1);
    app()->forgetInstance(LiveActivitiesConfig::class);
    app()->forgetInstance(ChannelRegistry::class);
    app()->forgetInstance(ActivityManager::class);
    $w->live->channelAnswers = [$w->channelCreated(CH_A)];
    $w->cli('mobile-push:channels:create');

    [$exit, $output] = $w->cli('mobile-push:channels:create');
    expect($exit)->toBe(1)->and($output)->toContain('local cap of 1')->and($w->live->managementRequests())->toHaveCount(1);
});
