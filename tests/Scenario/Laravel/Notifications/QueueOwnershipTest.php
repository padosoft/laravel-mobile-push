<?php

declare(strict_types=1);

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\PackageQueuedOrderShipped;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\QueuedOrderShipped;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\Shopper;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\NotificationsWorld;

beforeEach(function () {
    $this->world = NotificationsWorld::install();
    $this->user = Shopper::withId(1);
    $this->world->iphone($this->user);
});

it('queued notification: one Laravel job, the push is sent inside it, and a retry reuses the same effect and apns-id', function () {
    Queue::fake();

    $this->user->notify(new QueuedOrderShipped);

    $jobs = Queue::pushed(SendQueuedNotifications::class);
    expect($jobs)->toHaveCount(1)
        ->and(Queue::pushed(SendPushJob::class))->toHaveCount(0)
        ->and($this->world->push->transport->sentCount())->toBe(0);

    $job = $jobs->first();
    $job->handle(app(ChannelManager::class));
    $job->handle(app(ChannelManager::class)); // the worker retries the same serialized job

    $requests = $this->world->push->requestsTo('api.push.apple.com');
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->headers['apns-id'])->toBe($requests[1]->headers['apns-id'])
        ->and($job->notification->id)->not->toBeNull()
        ->and(Queue::pushed(SendPushJob::class))->toHaveCount(0)
        ->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(1);
});

it('package queueing: a non-queued notification that opts in sends nothing inline and creates exactly one package job', function () {
    Queue::fake();

    $this->user->notify(new PackageQueuedOrderShipped);

    expect(Queue::pushed(SendPushJob::class))->toHaveCount(1)
        ->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(0)
        ->and($this->world->push->transport->sentCount())->toBe(0);
});
