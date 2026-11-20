<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelLimitReachedException;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\MatchActivity;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/LiveWorld.php';

const MATCH_CHANNEL = 'dHN0LXNyY2gtY2hubA==';
const OTHER_CHANNEL = 'b3RoZXItY2hhbm5lbA==';

function created(string $id): Response
{
    return new Response(201, ['apns-channel-id' => $id, 'apns-request-id' => '11111111-1111-4111-8111-111111111111']);
}

function listed(string ...$ids): Response
{
    return new Response(200, [], (string) json_encode(['channels' => $ids]));
}

/*
 * A football match followed by thousands of fans: one broadcast channel, a publication per goal. APNs' acceptance
 * is one publication, never a count of devices.
 */
it('match day: two updates and an end go to the broadcast route as one publication each; a channel never starts an activity', function (): void {
    $world = LiveWorld::install();
    $world->channelAnswers = [created(MATCH_CHANNEL)];

    $channel = $world->manager()->createChannel(StoragePolicy::NoStorage, 'Derby');
    expect($channel->state)->toBe(ChannelState::Active)
        ->and($channel->channel_id)->toBe(MATCH_CHANNEL)
        ->and($world->managementRequests())->toHaveCount(1)
        ->and(json_decode($world->managementRequests()[0]->body, true))->toBe(['message-storage-policy' => 0, 'push-type' => 'LiveActivity']);

    $results = [];
    foreach ([['score' => '1-0'], ['score' => '2-0']] as $state) {
        $world->kit->clock->advance(600);
        $results[] = LiveActivity::broadcast(MATCH_CHANNEL)->update($state)->send();
    }
    $world->kit->clock->advance(600);
    $results[] = LiveActivity::broadcast($channel)->end(['score' => '2-0', 'final' => true])->send();

    $pushes = $world->pushes();
    expect($pushes)->toHaveCount(3);
    foreach ($pushes as $i => $request) {
        expect($request->url)->toBe('https://api.push.apple.com/4/broadcasts/apps/'.SendKit::BUNDLE)
            ->and($request->headers['apns-channel-id'])->toBe(MATCH_CHANNEL)
            ->and($request->headers['apns-push-type'])->toBe('liveactivity')
            ->and($request->headers['apns-expiration'])->toBe('0')
            ->and($request->headers)->not->toHaveKey('apns-topic')
            ->and(LiveWorld::aps($request)['event'])->toBe($i < 2 ? 'update' : 'end')
            ->and($results[$i]->status)->toBe(SendStatus::Accepted)
            ->and($results[$i]->broadcast)->toBeTrue()
            ->and($results[$i]->publications())->toBe(1);
    }

    $row = $channel->fresh();
    expect($row->publications)->toBe(3)
        ->and($row->last_content_state)->toBe(['score' => '2-0', 'final' => true])
        ->and($row->ended_at)->not->toBeNull();

    // After the end, a late goal update is not published.
    $world->kit->clock->advance(5);
    expect(LiveActivity::broadcast($channel)->update(['score' => '3-0'])->send()->outcome)->toBe(TransitionOutcome::Skipped);

    // Starting through a channel is refused before the network.
    expect(fn () => LiveActivity::broadcast($channel)->start())->toThrow(UnsupportedCapabilityException::class);
    expect($world->transport->requests())->toHaveCount(4);
});

it('two goals in the same second on a channel: one publication now, the latest score in the next second', function (): void {
    $world = LiveWorld::install();
    $world->channelAnswers = [created(MATCH_CHANNEL)];
    $channel = $world->manager()->createChannel(StoragePolicy::MostRecent);
    $world->kit->clock->advance(1);

    LiveActivity::broadcast($channel)->update(['score' => '1-0'])->send();
    $deferred = LiveActivity::broadcast($channel)->update(['score' => '1-1'])->send();
    expect($deferred->outcome)->toBe(TransitionOutcome::Deferred)->and($world->pushes())->toHaveCount(1);

    $world->kit->clock->advance(1);
    $world->manager()->flushDue();
    expect(array_map(static fn ($r) => LiveWorld::aps($r)['content-state'], $world->pushes()))->toBe([['score' => '1-0'], ['score' => '1-1']]);
});

it('the channel create response was lost: the row is unknown, nothing is retried, and sync adopts the orphan', function (): void {
    $world = LiveWorld::install();
    $world->channelAnswers = [new TransportFailure(TransportEvidence::Unknown, 92, 'stream reset after the request was written')];

    $channel = $world->manager()->createChannel(StoragePolicy::NoStorage, 'Final');

    expect($channel->state)->toBe(ChannelState::Unknown)
        ->and($channel->channel_id)->toBeNull()
        ->and($world->managementRequests())->toHaveCount(1);
    expect(fn () => LiveActivity::broadcast($channel)->update(['score' => '0-0'])->send()->status)->not->toThrow(Throwable::class);
    expect(LiveActivity::broadcast($channel)->update(['score' => '0-0'])->send()->status)->toBe(SendStatus::Skipped)
        ->and($world->pushes())->toBe([]);

    $world->channelAnswers = [listed(MATCH_CHANNEL)];
    $report = $world->channels()->sync($world->binding());

    $row = $channel->fresh();
    expect($report->adopted)->toBe([MATCH_CHANNEL])
        ->and($report->isConsistent())->toBeTrue()
        ->and($row->state)->toBe(ChannelState::Active)
        ->and($row->channel_id)->toBe(MATCH_CHANNEL)
        ->and($world->managementRequests())->toHaveCount(2)
        ->and($world->managementRequests()[1]->method)->toBe('GET')
        ->and($world->managementRequests()[1]->url)->toEndWith('/all-channels');
});

it('sync marks a channel deleted at APNs as deleted locally, and resolves an unknown create that never happened', function (): void {
    $world = LiveWorld::install();
    $world->channelAnswers = [created(MATCH_CHANNEL), created(OTHER_CHANNEL), new TransportFailure(TransportEvidence::Unknown, 92, 'lost')];
    $kept = $world->manager()->createChannel();
    $gone = $world->manager()->createChannel();
    $lost = $world->manager()->createChannel();

    $world->channelAnswers = [listed(MATCH_CHANNEL)];
    $report = $world->channels()->sync($world->binding());

    expect($report->deletedLocally)->toBe([OTHER_CHANNEL])
        ->and($report->neverCreated)->toBe(1)
        ->and($kept->fresh()->state)->toBe(ChannelState::Active)
        ->and($gone->fresh()->state)->toBe(ChannelState::Deleted)
        ->and($gone->fresh()->closed_reason?->value)->toBe('deleted_remotely')
        ->and($lost->fresh()->state)->toBe(ChannelState::Deleted);
});

it('sync never guesses between two unknown creates: both orphans are reported', function (): void {
    $world = LiveWorld::install();
    $world->channelAnswers = [new TransportFailure(TransportEvidence::Unknown, 92, 'lost'), new TransportFailure(TransportEvidence::Unknown, 92, 'lost')];
    $a = $world->manager()->createChannel();
    $b = $world->manager()->createChannel();

    $world->channelAnswers = [listed(MATCH_CHANNEL, OTHER_CHANNEL)];
    $report = $world->channels()->sync($world->binding());

    expect($report->adopted)->toBe([])
        ->and($report->orphans)->toBe([MATCH_CHANNEL, OTHER_CHANNEL])
        ->and($a->fresh()->state)->toBe(ChannelState::Unknown)
        ->and($b->fresh()->state)->toBe(ChannelState::Unknown);

    $world->channels()->adopt($a, MATCH_CHANNEL);
    expect($a->fresh()->state)->toBe(ChannelState::Active);
});

it('the local cap refuses a create before the network, and cleanup deletes channels no open activity uses', function (): void {
    $world = LiveWorld::install(['channels.max_per_environment' => 2, 'channels.cleanup_after_hours' => 1]);
    $world->channelAnswers = [created(MATCH_CHANNEL), created(OTHER_CHANNEL)];
    $used = $world->manager()->createChannel();
    $unused = $world->manager()->createChannel();

    expect(fn () => $world->manager()->createChannel())->toThrow(ChannelLimitReachedException::class);
    expect($world->managementRequests())->toHaveCount(2);

    // An activity on the device subscribed to the first channel.
    LiveWorld::rider(1)->reportLiveActivityToken($world->binding(), MatchActivity::class, LiveWorld::activityToken('m1'), new ActivityReport(clientActivityId: 'M-1', channelId: MATCH_CHANNEL));

    $world->kit->clock->advance(7200);
    $world->channelAnswers = [new Response(204)];
    expect(array_map(static fn ($c) => $c->channel_id, $world->channels()->unreferenced($world->binding())))->toBe([OTHER_CHANNEL])
        ->and($world->channels()->cleanup($world->binding()))->toBe(1);

    $delete = $world->managementRequests()[2];
    expect($delete->method)->toBe('DELETE')
        ->and($delete->headers['apns-channel-id'])->toBe(OTHER_CHANNEL)
        ->and($unused->fresh()->state)->toBe(ChannelState::Deleted)
        ->and($used->fresh()->state)->toBe(ChannelState::Active);

    // A slot is free again.
    $world->channelAnswers = [created('dGhpcmQtY2hhbm5lbA==')];
    expect($world->manager()->createChannel()->state)->toBe(ChannelState::Active);
});
