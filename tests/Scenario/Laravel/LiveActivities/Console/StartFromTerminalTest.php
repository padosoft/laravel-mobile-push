<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Console\ConsoleWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/ConsoleWorld.php';

/*
 * An operator starts a delivery activity from the terminal for rider 7: the command talks to the same pipeline as
 * the code API, and the only token it ever shows is the masked one.
 */
beforeEach(function (): void {
    $this->world = ConsoleWorld::install();
    $this->rider = $this->world->rider(7);
    $this->owner = ConsoleWorld::RIDER.':7';
});

it('start a delivery from the terminal: one request to the push-to-start token, masked token and activity id in the output', function (): void {
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', [
        'type' => 'delivery',
        '--owner' => $this->owner,
        '--attributes' => '{"order":"42"}',
        '--state' => '{"eta":20}',
        '--title' => 'Order confirmed',
        '--body' => 'Tap to follow your rider',
        '--input-push-token' => true,
    ]);

    $pushes = $this->world->live->pushes();
    expect($exit)->toBe(0)->and($pushes)->toHaveCount(1)
        ->and($pushes[0]->url)->toEndWith('/3/device/'.LiveWorld::pushToStart('p1')->value())
        ->and($pushes[0]->headers['apns-push-type'])->toBe('liveactivity');

    $aps = LiveWorld::aps($pushes[0]);
    expect($aps['event'])->toBe('start')
        ->and($aps['attributes-type'])->toBe('DeliveryAttributes')
        ->and($aps['attributes'])->toBe(['order' => '42'])
        ->and($aps['content-state'])->toBe(['eta' => 20])
        ->and($aps['alert'])->toBe(['title' => 'Order confirmed', 'body' => 'Tap to follow your rider'])
        ->and($aps['input-push-token'])->toBe(1);

    $row = PushActivity::blank()->newQuery()->first();
    expect($row->state)->toBe(ActivityState::Requested)
        ->and($output)->toContain(TokenMasker::mask(LiveWorld::pushToStart('p1')->value()))
        ->and($output)->toContain($row->id)
        ->and($output)->toContain('accepted');
});

it('a start without a title is refused with exit 2 and the reason, and nothing is sent', function (): void {
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $this->owner, '--state' => '{"eta":20}']);

    expect($exit)->toBe(2)
        ->and($output)->toContain('--title is required')->toContain('alert')
        ->and($this->world->live->transport->requests())->toBe([])
        ->and(PushActivity::blank()->newQuery()->count())->toBe(0);
});

it('invalid input is refused with exit 2 before any request', function (array $params, string $reason): void {
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', $params + ['type' => 'delivery', '--owner' => $this->owner, '--title' => 'Hi']);

    expect($exit)->toBe(2)->and($output)->toContain($reason)->and($this->world->live->transport->requests())->toBe([]);
})->with([
    'state is not valid JSON' => [['--state' => '{eta:'], '--state is not valid JSON'],
    'state is a list' => [['--state' => '[1,2]'], '--state must be a JSON object'],
    'attributes are a scalar' => [['--attributes' => '"x"'], '--attributes must be a JSON object'],
    'unknown type' => [['type' => 'nope'], 'Unknown Live Activity type "nope"'],
    'owner without an id' => [['--owner' => 'Rider'], '--owner must look like Type:id'],
    'owner that is no model' => [['--owner' => 'stdClass:1'], 'neither an Eloquent model class'],
]);

it('an owner with no push-to-start token gets a clear message and no request', function (): void {
    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => ConsoleWorld::RIDER.':99', '--title' => 'Hi']);

    expect($exit)->toBe(1)->and($output)->toContain('No active push-to-start token')->and($this->world->live->transport->requests())->toBe([]);
});

it('--json prints rows with masked tokens and the activity ids', function (): void {
    $report = $this->world->json('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $this->owner, '--title' => 'Hi', '--state' => '{"eta":1}']);

    expect($report['ok'])->toBeTrue()->and($report['accepted'])->toBe(1)
        ->and($report['results'][0]['token'])->toBe(TokenMasker::mask(LiveWorld::pushToStart('p1')->value()))
        ->and($report['results'][0]['status'])->toBe('accepted')
        ->and($report['results'][0]['activity'])->toBe(PushActivity::blank()->newQuery()->first()->id);
});

it('a start that APNs refuses exits 1 and says so', function (): void {
    $this->world->live->pushAnswers = [SendKit::apnsReason(400, 'BadDeviceToken')];

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $this->owner, '--title' => 'Hi']);

    expect($exit)->toBe(1)->and($output)->toContain('rejected')->toContain('0 of 1');
});

it('simulate mode: the start reports what it would do, writes no row and makes no request', function (): void {
    $this->world->withOptions(SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate'));

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $this->owner, '--title' => 'Hi']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Simulation mode: nothing was sent')->toContain('start "DeliveryAttributes"')
        ->toContain(TokenMasker::mask(LiveWorld::pushToStart('p1')->value()))
        ->and($this->world->live->transport->requests())->toBe([])
        ->and(PushActivity::blank()->newQuery()->count())->toBe(0);
});

it('outside production without authorisation the start is blocked with the remedy; with it, it goes out to the allowlisted device only', function (): void {
    $this->world->withOptions(SendKit::live(applicationEnvironment: 'staging'));

    [$exit, $output] = $this->world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $this->owner, '--title' => 'Hi']);

    expect($exit)->toBe(1)->and($output)->toContain('Blocked by safe mode')->toContain('live.allow')
        ->and($this->world->live->transport->requests())->toBe([])
        ->and(PushActivity::blank()->newQuery()->count())->toBe(0);

    // The test pipeline hashes with the kit's index: the command's own guard must use the same one.
    app()->instance(TokenIndex::class, $this->world->live->kit->index);
    $allowed = app(TokenIndex::class)->index(LiveWorld::pushToStart('p1'), 'default');
    $this->world->withOptions(SendKit::live(applicationEnvironment: 'staging', live: new LiveSettings(allow: true, onlyTo: [$allowed])));

    [$exit] = $this->world->cli('mobile-push:live-activity:start', ['type' => 'delivery', '--owner' => $this->owner, '--title' => 'Hi']);
    expect($exit)->toBe(0)->and($this->world->live->pushes())->toHaveCount(1);
});
