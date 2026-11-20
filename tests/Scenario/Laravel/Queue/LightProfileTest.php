<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Light profile: best effort, no tables. Small encrypted jobs; deferred destinations travel on in a NEW job with
 * their budgets; nothing is released, nothing is retried by the queue.
 */

function lightQueue(): QueuedSender
{
    return app(QueuedSender::class);
}

it('queue it and forget: 1,200 tokens become 3 encrypted jobs, and running them sends 1,200 requests', function (): void {
    $w = QueueWorld::boot('light');
    QueueWorld::databaseQueue();
    $w->dispatcher->add($w->kit->apnsDriver());

    lightQueue()->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens(1200), SendKit::live(), new QueueOptions);

    $jobs = QueueWorld::storedJobs();
    expect($jobs)->toHaveCount(3);
    $sizes = [];
    foreach ($jobs as $job) {
        $raw = json_encode($job['payload']);
        foreach ([0, 499, 500, 1199] as $i) {
            expect($raw)->not->toContain(QueueWorld::token($i)->value());
        }
        $stored = QueueWorld::jobIn($job['payload']);
        $sizes[] = count($stored->destinations);
        expect($job['queue'])->toBe('push')
            ->and($job['payload']['displayName'])->toStartWith('mobile-push send apns/ios')
            ->and($stored->tags())->toContain('mobile-push', 'provider:apns', 'connection:ios', 'scope:default');
    }
    expect($sizes)->toBe([500, 500, 200]);

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'push', '--stop-when-empty' => true]);

    expect($w->transport->sentCount())->toBe(1200)
        ->and($w->apnsRequestsPerToken())->toHaveCount(1200)
        ->and(array_values(array_unique($w->apnsRequestsPerToken())))->toBe([1])
        ->and(QueueWorld::storedJobs())->toBe([]);
});

it('FCM says slow down: only the deferred destinations travel on, in one new delayed job, with their budgets', function (): void {
    $w = QueueWorld::boot('light');
    QueueWorld::databaseQueue();
    $w->dispatcher->add($w->kit->fcmDriver());
    $slow = [SendKit::fcmToken(2)->value(), SendKit::fcmToken(4)->value()];
    $w->transport->respondUsing(static fn (Request $r) => in_array(QueueWorld::fcmTokenOf($r), $slow, true)
        ? SendKit::fcmError(503, 'UNAVAILABLE')
        : SendKit::fcmOk());

    lightQueue()->queue(SendKit::fcmBinding(), PushMessage::make('Order', 'Shipped')->ttl(7200), [
        SendKit::fcmToken(1), SendKit::fcmToken(2), SendKit::fcmToken(3), SendKit::fcmToken(4), SendKit::fcmToken(5),
    ], SendKit::live(), new QueueOptions);
    [$original] = QueueWorld::storedJobs();
    $now = $w->kit->clock->now()->getTimestamp();

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'push', '--stop-when-empty' => true]);

    $left = QueueWorld::storedJobs();
    expect($left)->toHaveCount(1)
        ->and($left[0]['id'])->not->toBe($original['id'])
        ->and($left[0]['payload']['uuid'])->not->toBe($original['payload']['uuid'])
        ->and($left[0]['attempts'])->toBe(0)
        ->and($left[0]['available_at'] - $now)->toBeGreaterThanOrEqual(60);
    $continuation = QueueWorld::jobIn($left[0]['payload']);
    expect($continuation)->toBeInstanceOf(SendPushJob::class)
        ->and(array_column($continuation->destinations, 'v'))->toEqualCanonicalizing($slow)
        ->and(array_map(static fn (array $d): int => $d['b']['attempts'], $continuation->destinations))->toBe([1, 1])
        ->and($continuation->continuation)->toBe(1);

    // The provider is back once the delay has passed: the second attempt continues the same budget.
    $w->transport->respondUsing(static fn () => SendKit::fcmOk());
    $w->advance($left[0]['available_at'] - $now);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'push', '--stop-when-empty' => true]);

    $perToken = $w->fcmRequestsPerToken();
    $last = array_slice($w->results->results, -2);
    expect($perToken[$slow[0]])->toBe(2)
        ->and($perToken[$slow[1]])->toBe(2)
        ->and($perToken[SendKit::fcmToken(1)->value()])->toBe(1)
        ->and(array_map(static fn (array $r) => $r['result']->status, $last))->toBe([SendStatus::Accepted, SendStatus::Accepted])
        ->and(array_map(static fn (array $r) => $r['result']->attempts, $last))->toBe([2, 2])
        ->and(QueueWorld::storedJobs())->toBe([])
        ->and($w->kit->sleeps)->toBe([]);
});

it('the alert expires in the queue: a job run after the pinned expiry sends nothing', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $w->dispatcher->add($w->kit->apnsDriver());

    lightQueue()->queue(SendKit::apnsBinding(), PushMessage::make('Your code', '123456')->ttl(60), SendKit::apnsTokens(3), SendKit::live(), new QueueOptions);
    $job = Queue::pushed(SendPushJob::class)->first();

    $w->advance(120);
    $w->run($job);

    expect($w->transport->sentCount())->toBe(0)
        ->and(array_map(static fn (array $r) => $r['result']->status, $w->results->results))->toBe([SendStatus::Expired, SendStatus::Expired, SendStatus::Expired]);
    Queue::assertPushed(SendPushJob::class, 1);
});

it('a stale job for a re-assigned connection: the binding no longer matches, nothing is sent, the job ends cleanly', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $w->dispatcher->add($w->kit->apnsDriver());   // "ios" now points at com.example.app
    $stale = new BindingRef('default', 'ios', Provider::Apns, 'com.previous.app', Environment::Production);

    lightQueue()->queue($stale, PushMessage::make('Hello', 'There'), SendKit::apnsTokens(2), SendKit::live(), new QueueOptions);
    $w->run(Queue::pushed(SendPushJob::class)->first());

    expect($w->transport->sentCount())->toBe(0);
    Queue::assertPushed(SendPushJob::class, 1);   // no continuation either
});

it('sends bulk traffic on the bulk queue and honours an explicit queue, connection and delay', function (): void {
    Queue::fake();
    QueueWorld::boot('light');

    lightQueue()->queue(SendKit::apnsBinding(), PushMessage::make('A', 'B'), SendKit::apnsTokens(1), SendKit::live(trafficClass: TrafficClass::Bulk), new QueueOptions);
    lightQueue()->queue(SendKit::apnsBinding(), PushMessage::make('A', 'B'), SendKit::apnsTokens(1), SendKit::live(), new QueueOptions('redis', 'vip', 30));

    $jobs = Queue::pushed(SendPushJob::class)->values();
    expect($jobs[0]->queue)->toBe('push-bulk')
        ->and([$jobs[1]->connection, $jobs[1]->queue, $jobs[1]->delay])->toBe(['redis', 'vip', 30])
        ->and($jobs[0]->tries)->toBe(1);
});
