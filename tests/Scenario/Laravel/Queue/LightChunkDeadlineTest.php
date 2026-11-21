<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Queue\QueueModule;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Laravel\Sending\Events\BindingMismatchDetected;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Light profile, chunk deadline (plan §6.6): one light job stops giving destinations to the Sender at
 * queue.chunk_deadline (the setting the durable relay enforces) and hands the ones it never attempted to ONE
 * follow-up job. The oracles are the requests the fake transport received, the jobs on the queue fake and the
 * public results; the clock is the world's fake clock, moved by the "provider" while it answers.
 */

/** A light world on the queue fake: one APNs connection, the Sender's window forced to $window. */
function deadlineWorld(int $deadline, int $window = 2): QueueWorld
{
    Queue::fake();
    $w = QueueWorld::boot('light', ['queue.chunk_deadline' => $deadline]);
    $w->dispatcher->add($w->kit->apnsDriver());
    $w->dispatcher->window = $window;

    return $w;
}

/** A provider that takes $seconds per request (the fake clock moves while it answers). */
function slowProvider(QueueWorld $w, int $seconds): void
{
    $w->transport->respondUsing(static function () use ($w, $seconds) {
        $w->advance($seconds);

        return new Response(200);
    });
}

/** @return list<SendPushJob> every light job on the queue fake, in dispatch order */
function lightJobs(): array
{
    return Queue::pushed(SendPushJob::class)->values()->all();
}

/** @return list<string> the token values a light job carries */
function carried(SendPushJob $job): array
{
    return array_column($job->destinations, 'v');
}

/** @return list<string> */
function tokenValues(int $n, int $from = 0): array
{
    return array_map(static fn ($t): string => $t->value(), iterator_to_array(QueueWorld::tokens($n, $from), false));
}

it('a chunk that would outlast its deadline is cut there; exactly one follow-up completes it, every token requested once', function (): void {
    $w = deadlineWorld(deadline: 10);
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens(6), SendKit::live(), new QueueOptions);
    [$first] = lightJobs();
    slowProvider($w, 4);   // 8 s per window of 2: after the first window 2 s are left, less than one window

    $w->run($first);

    $jobs = lightJobs();
    expect($w->transport->sentCount())->toBe(2)
        ->and(array_keys($w->apnsRequestsPerToken()))->toBe(tokenValues(2))
        ->and($jobs)->toHaveCount(2);
    $handover = $jobs[1];
    expect(carried($handover))->toBe(tokenValues(4, from: 2))
        // never attempted: carried exactly as they were queued (no budget, no attempt counted)
        ->and($handover->destinations)->toBe(array_slice($first->destinations, 2))
        ->and([$handover->continuation, $handover->delay, $handover->queue, $handover->effectId, $handover->effectKey, $handover->expiresAtMs])
        ->toBe([1, null, 'push', $first->effectId, $first->effectKey, $first->expiresAtMs]);

    slowProvider($w, 1);   // the provider is quick again: the follow-up (fresh deadline) finishes the chunk
    $w->run($handover);

    expect($w->transport->sentCount())->toBe(6)
        ->and(array_keys($w->apnsRequestsPerToken()))->toBe(tokenValues(6))
        ->and(array_values(array_unique($w->apnsRequestsPerToken())))->toBe([1])
        ->and(array_map(static fn (array $r) => $r['result']->status, $w->results->results))->toBe(array_fill(0, 6, SendStatus::Accepted))
        ->and(lightJobs())->toHaveCount(2);   // nothing more was queued
});

it('a chunk that fits in its deadline is untouched: no follow-up', function (): void {
    $w = deadlineWorld(deadline: 10);
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens(6), SendKit::live(), new QueueOptions);
    slowProvider($w, 1);   // 2 s per window, 6 s for the chunk
    $start = $w->kit->clock->now()->getTimestamp();

    $w->run(lightJobs()[0]);

    expect($w->transport->sentCount())->toBe(6)
        ->and(array_values(array_unique($w->apnsRequestsPerToken())))->toBe([1])
        ->and($w->kit->clock->now()->getTimestamp() - $start)->toBe(6)
        ->and(lightJobs())->toHaveCount(1);
});

it('the deadline passing while a window is in flight never re-sends that window, even when its outcome is unknown', function (): void {
    $w = deadlineWorld(deadline: 10);
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Your code', '123456'), QueueWorld::tokens(6), SendKit::live(), new QueueOptions);
    $lost = QueueWorld::token(1)->value();
    // 6 s per request: the first window (2 requests) ends at 12 s, past the 10 s deadline, while it is on the wire.
    // The response to the second request is lost after the request was written.
    $w->transport->respondUsing(static function (Request $r) use ($w, $lost) {
        $w->advance(6);

        return SendKit::tokenOf($r) === $lost
            ? new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream was reset after the request was written')
            : new Response(200);
    });

    $w->run(lightJobs()[0]);

    $handover = lightJobs()[1];
    expect(carried($handover))->toBe(tokenValues(4, from: 2))
        ->and(array_map(static fn (array $r) => $r['result']->status, $w->results->results))->toBe([SendStatus::Accepted, SendStatus::OutcomeUnknown]);

    slowProvider($w, 0);
    $w->run($handover);

    // Both requests of the window in flight left exactly once; the unknown one was not retried by a follow-up.
    expect($w->apnsRequestsPerToken())->toBe(array_fill_keys(tokenValues(6), 1))
        ->and(lightJobs())->toHaveCount(2);
});

it('a provider slower than the deadline: every job sends one window and hands over the rest, the chain ends with the chunk', function (): void {
    $w = deadlineWorld(deadline: 10);
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens(6), SendKit::live(), new QueueOptions);
    slowProvider($w, 11);   // each window of 2 takes 22 s, more than the whole deadline

    for ($i = 0; $i < 10 && $i < count(lightJobs()); $i++) {
        $w->run(lightJobs()[$i]);
    }

    $jobs = lightJobs();
    expect($jobs)->toHaveCount(3)   // ceil(6 / 2): each job made progress, nobody waits for a sweeper
        ->and(array_map(static fn (SendPushJob $j): int => $j->continuation, $jobs))->toBe([0, 1, 2])
        ->and(array_map(static fn (SendPushJob $j): int => count($j->destinations), $jobs))->toBe([6, 4, 2])
        ->and($w->apnsRequestsPerToken())->toBe(array_fill_keys(tokenValues(6), 1));
});

it('a continuation cut by its deadline hands over the deferred budgets unchanged, and they are honoured', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light', ['queue.chunk_deadline' => 10]);
    $w->dispatcher->add($w->kit->fcmDriver());
    $w->dispatcher->window = 2;
    $w->transport->respondUsing(static fn () => SendKit::fcmError(503, 'UNAVAILABLE'));
    $tokens = array_map(static fn (int $i) => SendKit::fcmToken($i), range(1, 4));
    app(QueuedSender::class)->queue(SendKit::fcmBinding(), PushMessage::make('Order', 'Shipped')->ttl(7200), $tokens, SendKit::live(), new QueueOptions);
    $w->run(lightJobs()[0]);   // all four deferred: one delayed continuation with their budgets
    $continuation = lightJobs()[1];
    // what each destination already spent (the throttle gate may defer some without an attempt): the existing rule
    $spent = array_combine(array_column($continuation->destinations, 'v'), array_map(static fn (array $d): int => $d['b']['attempts'], $continuation->destinations));
    expect($continuation->destinations)->each->toHaveKey('b');

    $w->advance((int) $continuation->delay);
    $w->transport->respondUsing(static function () use ($w) {
        $w->advance(4);

        return SendKit::fcmOk();
    });
    $w->run($continuation);

    $handover = lightJobs()[2];
    expect($handover->continuation)->toBe(2)
        ->and($handover->delay)->toBeNull()
        // the two never attempted by the continuation keep their budgets, byte for byte
        ->and($handover->destinations)->toBe(array_slice($continuation->destinations, 2));

    $w->transport->respondUsing(static fn () => SendKit::fcmOk());
    $w->run($handover);

    // Every destination: exactly one more request than its budget had spent, accepted, its attempts continued.
    $final = [];
    foreach ($w->results->results as $r) {
        $final[$r['result']->destination->value()] = $r['result'];
    }
    expect($w->fcmRequestsPerToken())->toEqualCanonicalizing(array_map(static fn (int $n): int => $n + 1, $spent))
        ->and(array_map(static fn ($r) => $r->status, $final))->toBe(array_fill_keys(array_keys($final), SendStatus::Accepted))
        ->and(array_map(static fn ($r) => $r->attempts, array_intersect_key($final, $spent)))->toEqual(array_map(static fn (int $n): int => $n + 1, $spent))
        ->and(lightJobs())->toHaveCount(3);
});

it('after a credential rotation the follow-up carries the binding and registry references it was queued with, as a continuation does', function (): void {
    $w = deadlineWorld(deadline: 10);
    $tokens = [];
    foreach (QueueWorld::tokens(4) as $i => $token) {
        $tokens[] = $token->withRef(new DestinationRef('dev-'.$i, 3, 'App\\Models\\User', '42'));
    }
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), $tokens, SendKit::live(), new QueueOptions);
    $first = lightJobs()[0];
    slowProvider($w, 4);
    $w->run($first);
    $handover = lightJobs()[1];

    expect($handover->binding)->toBe($first->binding)
        ->and(array_column($handover->destinations, 'r'))->toBe([['dev-2', 3, 'App\\Models\\User', '42'], ['dev-3', 3, 'App\\Models\\User', '42']]);

    // The connection's credentials were rotated (binding generation 2, same app): the identity still matches, so the
    // follow-up sends through the current driver.
    $rotated = new BindingRef('default', 'ios', Provider::Apns, SendKit::BUNDLE, Environment::Production, '2');
    $w->dispatcher->add($w->kit->apnsDriver(binding: $rotated));
    slowProvider($w, 0);
    $w->run($handover);

    expect($w->apnsRequestsPerToken())->toBe(array_fill_keys(tokenValues(4), 1))
        ->and(lightJobs())->toHaveCount(2);
});

it('a follow-up whose connection was re-pointed to another app sends nothing, like any stale light job', function (): void {
    $w = deadlineWorld(deadline: 10);
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens(4), SendKit::live(), new QueueOptions);
    slowProvider($w, 4);
    $w->run(lightJobs()[0]);
    $handover = lightJobs()[1];

    $w->dispatcher->add($w->kit->apnsDriver(binding: SendKit::apnsBinding(bundle: 'com.other.app')));
    Event::fake([BindingMismatchDetected::class]);
    $sink = QueueWorld::recordingSinks();
    $w->run($handover);

    expect($w->transport->sentCount())->toBe(2)
        ->and(array_map(static fn ($r): string => $r->status->value.'/'.$r->reasonCode->value.'/'.$r->attempts, $sink->records))
        ->toBe(['skipped/'.ReasonCode::BindingMismatch->value.'/0', 'skipped/'.ReasonCode::BindingMismatch->value.'/0'])
        ->and(lightJobs())->toHaveCount(2);
    Event::assertDispatchedTimes(BindingMismatchDetected::class, 1);
});

it('the chain doctor prints for the light profile holds: with the defaults a slow provider no longer runs a job into its timeout', function (): void {
    $w = QueueWorld::boot('light');
    QueueWorld::databaseQueue();
    config()->set('queue.connections.database.retry_after', 90);   // a stock Laravel application
    config()->set('mobile-push.queue.worker_timeout', 55);          // what mobile-push:install prints
    $w->dispatcher->add($w->kit->apnsDriver());
    $w->dispatcher->window = 2;

    // What doctor states (its JSON output): every link of the chain holds, chunk deadline < job timeout included.
    Artisan::call('mobile-push:doctor', ['--json' => true]);
    $doctor = json_decode(Artisan::output(), true);
    $queueChecks = array_values(array_filter($doctor['checks'], static fn (array $c): bool => $c['section'] === 'Queue timeouts'));
    // ... and one information line says what the order of the values cannot promise: a window that starts right
    // before the deadline may run for the transport timeout.
    $links = array_values(array_filter($queueChecks, static fn (array $c): bool => $c['status'] !== 'info'));
    $notes = array_values(array_filter($queueChecks, static fn (array $c): bool => $c['status'] === 'info'));
    expect(array_column($links, 'status'))->toBe(array_fill(0, count($links), 'ok'))
        ->and(array_column($links, 'label'))->toContain('chunk_deadline (40 s) < job_timeout (50 s), margin 5 s')
        ->and($notes)->toHaveCount(1)
        ->and($notes[0]['label'])->toContain('Worst case')->toContain('chunk deadline (40 s)')->toContain('transport timeout (30 s)')->toContain('above 70 s')
        ->and(QueueModule::timeouts(app())->isVerified())->toBeTrue();

    // What a light job does with those defaults: a provider answering just under the transport timeout per window
    // (14 s per request, 28 s per window of 2). Without the deadline the 6 destinations would keep this job busy for
    // 84 s, far past its 50 s timeout; with it, every job returns before its timeout and nothing is lost or doubled.
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens(6), SendKit::live(), new QueueOptions);
    $durations = [];
    $w->transport->respondUsing(static function () use ($w) {
        $w->advance(14);

        return new Response(200);
    });
    while (($stored = QueueWorld::storedJobs()) !== [] && count($durations) < 10) {
        $job = QueueWorld::jobIn($stored[0]['payload']);
        DB::table('jobs')->where('id', $stored[0]['id'])->delete();
        $start = $w->kit->clock->now()->getTimestamp();
        $w->run($job);
        $durations[] = $w->kit->clock->now()->getTimestamp() - $start;
    }

    expect($durations)->toBe([28, 28, 28])
        ->and(max($durations))->toBeLessThan((int) config('mobile-push.queue.timeout', 50))
        ->and($w->apnsRequestsPerToken())->toBe(array_fill_keys(tokenValues(6), 1));
});
