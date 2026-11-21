<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * What a caller can observe about a light-profile queue() (the default profile): the effect id it returns, the job's
 * tags, and, when a worker runs the job, the same events a direct send dispatches. There is no report to read.
 */
beforeEach(function () {
    $this->world = W::install(['connections' => ['ios' => W::apns()], 'delivery.profile' => 'light']);
    config()->set('queue.default', 'sync');
});

it('returns only an effect id; the outcomes arrive as events when the job runs', function () {
    $gone = W::apnsToken('gone');
    $this->world->answer = static fn (Request $r): ?Response => str_ends_with($r->url, $gone)
        ? new Response(410, [], '{"reason":"Unregistered","timestamp":1763632800000}')
        : null;

    $effectId = MobilePush::to([ApnsDeviceToken::of(W::apnsToken('ok')), ApnsDeviceToken::of(W::apnsToken('gone'))])
        ->queue(PushMessage::make('Later'));

    $results = array_map(static fn (PushResultProduced $e) => $e->result->status, $this->world->eventsOf(PushResultProduced::class));
    $batch = $this->world->eventsOf(PushBatchCompleted::class);
    expect($effectId)->toBeString()->not->toBe('')->not->toContain(',')
        ->and($this->world->pushRequests())->toHaveCount(2)
        ->and($results)->toBe([SendStatus::Accepted, SendStatus::Rejected])
        ->and($batch)->toHaveCount(1)
        ->and($batch[0]->total)->toBe(2);
});

it('tags the job with the effect id it returned, which is how Horizon finds it', function () {
    Queue::fake();

    $effectId = MobilePush::to(ApnsDeviceToken::of(W::apnsToken('tagged')))->queue(PushMessage::make('Later'));

    Queue::assertPushed(SendPushJob::class, static fn (SendPushJob $job): bool => $job->effectId === $effectId
        && in_array('effect:'.$effectId, $job->tags(), true));
    expect($this->world->pushRequests())->toBeEmpty();
});
