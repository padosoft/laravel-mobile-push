<?php

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignsModule;
use Padosoft\MobilePush\Laravel\Campaigns\Console\ConsoleCommands;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\Console\CampaignConsole;

require_once __DIR__.'/CampaignConsole.php';

$commands = [
    'list' => ['mobile-push:campaign:list', []],
    'status' => ['mobile-push:campaign:status', ['campaign' => 'x']],
    'pause' => ['mobile-push:campaign:pause', ['campaign' => 'x']],
    'resume' => ['mobile-push:campaign:resume', ['campaign' => 'x']],
    'cancel' => ['mobile-push:campaign:cancel', ['campaign' => 'x', '--force' => true]],
    'reconcile' => ['mobile-push:campaign:reconcile', ['campaign' => 'x']],
    'sweep' => ['mobile-push:campaign:sweep', []],
    'prune' => ['mobile-push:campaign:prune', []],
];

it('another tenant\'s campaign id is not found: exit 2, and the campaign is untouched', function (): void {
    $c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
    $campaign = $c->world->dispatch(DeviceAudience::all());

    foreach (['status', 'pause', 'resume', 'reconcile'] as $verb) {
        [$exit, $output] = $c->cli('mobile-push:campaign:'.$verb, ['campaign' => $campaign->id, '--scope' => 'tenant-b']);
        expect($exit)->toBe(2)->and($output)->toContain('No campaign '.$campaign->id.' in scope tenant-b');
    }
    [$exit] = $c->cli('mobile-push:campaign:cancel', ['campaign' => $campaign->id, '--scope' => 'tenant-b', '--force' => true]);
    $json = $c->json('mobile-push:campaign:status', ['campaign' => 'does-not-exist']);

    expect($exit)->toBe(2)->and($json['ok'])->toBeFalse()->and($json['exit'])->toBe(2)
        ->and($c->world->campaigns()->find('default', $campaign->id)?->status->value)->toBe('planning');
});

it('module disabled: every command exits 1 and says how to enable it', function (string $command, array $params): void {
    $c = CampaignConsole::install();
    config()->set('mobile-push.campaigns.enabled', false);

    [$exit, $output] = $c->cli($command, $params);

    expect($exit)->toBe(1)->and($output)->toContain('Campaigns are disabled')->toContain('mobile-push.campaigns.enabled');
})->with($commands);

it('light profile: every command exits 1 and names the durable profile', function (string $command, array $params): void {
    $c = CampaignConsole::install();
    config()->set('mobile-push.delivery.profile', 'light');

    [$exit, $output] = $c->cli($command, $params);
    $json = $c->json($command, $params);

    expect($exit)->toBe(1)->and($output)->toContain('durable delivery profile')
        ->and($json['ok'])->toBeFalse()->and($json['exit'])->toBe(1)->and($json['error'])->toContain('durable');
})->with($commands);

it('canary: a whole session of commands, human and JSON, never prints a clear token', function (): void {
    $c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
    $c->lossyTransport();
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain();

    // cli() and json() fail the test when any of the 200 seeded tokens shows up in an output.
    foreach (['list', 'status', 'reconcile'] as $verb) {
        $params = $verb === 'list' ? [] : ['campaign' => $campaign->id];
        $c->cli('mobile-push:campaign:'.$verb, $params);
        $c->json('mobile-push:campaign:'.$verb, $params);
    }
    $c->cli('mobile-push:campaign:sweep');
    $c->cli('mobile-push:campaign:prune', ['--pretend' => true]);
    [$exit] = $c->cli('mobile-push:campaign:cancel', ['campaign' => $campaign->id, '--force' => true]);

    expect($exit)->toBe(1);   // already completed
});

it('the module registers the eight commands only when campaigns are enabled', function (): void {
    config()->set('mobile-push.campaigns.enabled', true);
    CampaignsModule::boot($this->app);
    $names = array_keys((new ConsoleApplication($this->app, $this->app['events'], '1.0'))->all());

    expect($names)->toContain('mobile-push:campaign:status', 'mobile-push:campaign:prune')
        ->and(count(ConsoleCommands::all()))->toBe(8);

    config()->set('mobile-push.campaigns.enabled', false);
    $names = array_keys((new ConsoleApplication($this->app, $this->app['events'], '1.0'))->all());
    expect($names)->not->toContain('mobile-push:campaign:status');
});
