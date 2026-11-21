<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\Console\CampaignConsole;

require_once __DIR__.'/CampaignConsole.php';

beforeEach(function (): void {
    $this->c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
});

it('pause, look, resume: planning stops, status says paused, resume continues to the whole audience', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(1);   // the first slice of 100

    [$exit, $output] = $c->cli('mobile-push:campaign:pause', ['campaign' => $campaign->id]);
    $c->world->drain();    // the queued second slice finds the campaign paused

    expect($exit)->toBe(0)->and($output)->toContain('PAUSED')->toContain('No further slice is planned')
        ->and($c->world->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Paused)
        ->and(CampaignWorld::deliveries($campaign->id))->toBe(100);

    [, $status] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id]);
    expect($status)->toContain('PAUSED')->toContain('Planned: 100 destinations so far');

    [$again, $text] = $c->cli('mobile-push:campaign:pause', ['campaign' => $campaign->id]);
    expect($again)->toBe(0)->and($text)->toContain('already paused');

    [$exit, $output] = $c->cli('mobile-push:campaign:resume', ['campaign' => $campaign->id]);
    $c->world->drain();

    expect($exit)->toBe(0)->and($output)->toContain('PLANNING')->toContain('stored cursor')
        ->and(CampaignWorld::deliveries($campaign->id))->toBe(200)
        ->and($c->world->campaigns()->progress('default', $campaign->id)->accepted)->toBe(200);
});

it('pausing a completed campaign exits 1 with the reason and changes nothing; so does resuming it', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain();
    $c->world->campaigns()->progress('default', $campaign->id);

    foreach (['pause', 'resume'] as $verb) {
        [$exit, $output] = $c->cli('mobile-push:campaign:'.$verb, ['campaign' => $campaign->id]);
        expect($exit)->toBe(1)->and($output)->toContain('completed')->toContain('nothing was changed');
    }
    $json = $c->json('mobile-push:campaign:pause', ['campaign' => $campaign->id]);
    expect($json['ok'])->toBeFalse()->and($json['exit'])->toBe(1)->and($json['error'])->toContain('cannot be paused')
        ->and($c->world->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Completed);
});

it('cancel asks first; declining changes nothing', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(2);

    $this->artisan('mobile-push:campaign:cancel', ['campaign' => $campaign->id])
        ->expectsOutputToContain('CANNOT be undone')
        ->expectsConfirmation('Cancel this campaign?', 'no')
        ->expectsOutputToContain('nothing was changed')
        ->assertExitCode(1);

    expect($c->world->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Running)
        ->and(CampaignWorld::states($campaign->id))->toBe(['pending' => 200]);
});

it('cancel confirmed: cancelled, and the output names what cannot be undone', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(2);

    $this->artisan('mobile-push:campaign:cancel', ['campaign' => $campaign->id])
        ->expectsConfirmation('Cancel this campaign?', 'yes')
        ->expectsOutputToContain('is now CANCELLED')
        ->expectsOutputToContain('200 deliveries were cancelled before being sent')
        ->expectsOutputToContain('Cannot be undone: 0 already accepted, 0 unknown outcomes')
        ->assertExitCode(0);

    $c->world->drain();
    expect($c->world->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Cancelled)
        ->and(CampaignWorld::states($campaign->id))->toBe(['cancelled' => 200])
        ->and($c->world->queue->transport->sentCount())->toBe(0);
});

it('cancel mid-flight with --force: what was already accepted is reported as not undone; --json is explicit', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(1);
    $c->world->drain(1);   // second slice
    $c->world->drain();    // everything sent

    // Completed by now: cancelling it is refused with the reason.
    $c->world->campaigns()->progress('default', $campaign->id);
    [$exit, $output] = $c->cli('mobile-push:campaign:cancel', ['campaign' => $campaign->id, '--force' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('completed');

    $second = $c->world->dispatch(DeviceAudience::all(), name: 'second');
    $c->world->drain(1);
    $json = $c->json('mobile-push:campaign:cancel', ['campaign' => $second->id, '--force' => true]);
    expect($json['ok'])->toBeTrue()->and($json['changed'])->toBeTrue()->and($json['campaign']['status'])->toBe('cancelled')
        ->and($json)->toHaveKey('not_undone');
});

it('cancel with --json and no --force does not prompt and does nothing', function (): void {
    $c = $this->c;
    $campaign = $c->world->dispatch(DeviceAudience::all());

    $json = $c->json('mobile-push:campaign:cancel', ['campaign' => $campaign->id]);

    expect($json['ok'])->toBeFalse()->and($json['error'])->toContain('--force')
        ->and($c->world->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Planning);
});
