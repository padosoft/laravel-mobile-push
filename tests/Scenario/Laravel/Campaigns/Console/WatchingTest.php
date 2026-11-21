<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DestinationListAudience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\Sleeper;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\Console\CampaignConsole;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/CampaignConsole.php';

/*
 * Watching Black Friday from the terminal.
 */

it('status of a running campaign: planned, every state, unknown outcomes on their own line, the estimate labelled as such; --json has the same numbers', function (): void {
    $c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
    $c->lossyTransport();
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(2);                       // both slices planned, nothing sent yet

    [, $before] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id]);
    expect($before)->toContain('RUNNING')->toMatch('/Pending\s+200\b/')->toMatch('/UNKNOWN OUTCOME\s+0\b/');

    $c->world->queue->advance(30);
    $c->world->drain();                        // the workers run: 10 lost responses, 5 refusals

    [$exit, $output] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id]);
    $progress = $c->world->campaigns()->progress('default', $campaign->id);

    expect($exit)->toBe(0)
        ->and($output)->toContain('black-friday')->toContain($campaign->id)
        ->and($output)->toContain('Planned: 200 destinations')
        ->and($output)->toContain('Accepted')->toContain('Rejected')->toContain('Failed')->toContain('Deferred')->toContain('Pending')->toContain('Skipped')->toContain('Cancelled')
        ->and($progress->unknown)->toBe(10)
        ->and($output)->toMatch('/UNKNOWN OUTCOME\s+10\s+the response was lost/')
        ->and($output)->toContain('Estimate (a guess, not a promise)')
        ->and($output)->toContain('Receipts');

    $data = $c->json('mobile-push:campaign:status', ['campaign' => $campaign->id]);
    expect($data['ok'])->toBeTrue()
        ->and($data['planned'])->toBe($progress->campaign->planned)
        ->and($data['total'])->toBe($progress->total())
        ->and($data['counts']['accepted'])->toBe($progress->accepted)
        ->and($data['counts']['unknown_outcome'])->toBe($progress->unknown)
        ->and($data['counts']['rejected'])->toBe($progress->rejected)
        ->and($data['counts']['pending'] + $data['counts']['deferred'])->toBe($progress->waiting)
        ->and($data['campaign']['status'])->toBe($progress->campaign->status->value)
        ->and($data['estimate']['is_estimate'] ?? null)->toBeTrue();
});

it('a finished campaign with lost responses: the unknown outcomes are shown apart from failures', function (): void {
    $c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
    $c->lossyTransport();
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain();

    [$exit, $output] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id]);
    $data = $c->json('mobile-push:campaign:status', ['campaign' => $campaign->id]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('COMPLETED')
        ->and($output)->toMatch('/Accepted\s+185/')
        ->and($output)->toMatch('/Rejected\s+5\b/')
        ->and($output)->toMatch('/UNKNOWN OUTCOME\s+10\b/')
        ->and($data['counts'])->toMatchArray(['accepted' => 185, 'rejected' => 5, 'unknown_outcome' => 10, 'failed' => 0]);
});

it('a topic campaign: recipients are not known and no receipts funnel is shown', function (): void {
    $c = CampaignConsole::install();
    $c->world->answer(static fn ($r) => str_contains($r->url, 'fcm.googleapis.com') ? SendKit::fcmOk() : null);
    $campaign = $c->world->dispatch(DestinationListAudience::of([FcmTopic::of('black-friday')]), via: 'android');
    $c->world->drain();

    [$exit, $output] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id]);
    $data = $c->json('mobile-push:campaign:status', ['campaign' => $campaign->id]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Recipients are not known')
        ->and($output)->not->toContain('Receipts')
        ->and($data['recipients_known'])->toBeFalse()
        ->and($data['receipts'])->toBeNull();
});

it('--watch refreshes until the campaign is terminal, sleeping between refreshes', function (): void {
    $c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(2);

    // The sleeper stands in for time passing: each "sleep" lets the workers run one more job.
    $sleeper = new class($c->world) implements Sleeper
    {
        /** @var list<int> */
        public array $slept = [];

        public function __construct(private readonly CampaignWorld $world) {}

        public function sleep(int $seconds): void
        {
            $this->slept[] = $seconds;
            $this->world->drain(1);
        }
    };
    app()->instance(Sleeper::class, $sleeper);

    [$exit, $output] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id, '--watch' => '5']);

    expect($exit)->toBe(0)
        ->and($sleeper->slept)->not->toBe([])
        ->and(array_unique($sleeper->slept))->toBe([5])
        ->and(substr_count($output, 'Campaign black-friday'))->toBe(count($sleeper->slept) + 1)
        ->and($output)->toContain('COMPLETED')
        ->and($c->world->campaigns()->find('default', $campaign->id)?->status)->toBe(CampaignStatus::Completed);
});

it('--watch with --json prints one compact JSON document per refresh', function (): void {
    $c = CampaignConsole::install(['campaigns.slice' => 100]);
    CampaignWorld::seedDevices(CampaignConsole::DEVICES);
    $campaign = $c->world->dispatch(DeviceAudience::all());
    $c->world->drain(2);
    app()->instance(Sleeper::class, new class($c->world) implements Sleeper
    {
        public function __construct(private readonly CampaignWorld $world) {}

        public function sleep(int $seconds): void
        {
            $this->world->drain(1);
        }
    });

    [$exit, $output] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id, '--watch' => '1', '--json' => true]);
    $lines = array_values(array_filter(explode("\n", $output)));
    $last = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)->and(count($lines))->toBeGreaterThan(1)
        ->and($last['campaign']['status'])->toBe('completed')
        ->and($last['counts']['accepted'])->toBe(CampaignConsole::DEVICES);
});

it('--watch outside 1-3600 is invalid input (exit 2)', function (): void {
    $c = CampaignConsole::install();
    $campaign = $c->world->dispatch(DeviceAudience::all());

    [$exit, $output] = $c->cli('mobile-push:campaign:status', ['campaign' => $campaign->id, '--watch' => '0']);

    expect($exit)->toBe(2)->and($output)->toContain('--watch');
});
