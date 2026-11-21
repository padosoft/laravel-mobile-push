<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/OpsWorld.php';

/*
 * Cron-driven setups: no queue worker, the operator (or the scheduler) runs the relay and the sweeper as commands.
 * The oracle is the fake transport: how many requests really reached which host.
 */

it('relays queued durable deliveries inline and prints what it did', function (): void {
    $world = OpsWorld::boot();
    $tokens = array_map(static fn (string $s): string => W::apnsToken($s), ['a', 'b', 'c']);
    $world->secret(...$tokens);
    $world->queue(array_map(static fn (string $t) => ApnsDeviceToken::of($t), $tokens), 'order-1');
    Queue::assertPushed(RelayDeliveriesJob::class);   // a worker would get it; this installation has none
    expect($world->w->transport->sentCount())->toBe(0);

    $summary = $world->json('mobile-push:relay');

    expect($world->w->requestsTo('api.push.apple.com'))->toHaveCount(3)
        ->and($summary['claimed'])->toBe(3)
        ->and($summary['sent'])->toBe(3)
        ->and($summary['recorded'])->toBe(['accepted' => 3])
        ->and($summary['unknown_outcomes'])->toBe(0)
        ->and(OpsWorld::deliveryStates())->toBe(['accepted' => 3]);

    // Nothing is due any more: a second run makes no request.
    $again = $world->json('mobile-push:relay');
    expect($again['claimed'])->toBe(0)
        ->and($world->w->transport->sentCount())->toBe(3);

    [, $human] = $world->call('mobile-push:relay');
    expect($human)->toContain('Relay finished')->toContain('claimed');
    $world->assertClean();
});

it('shows the next attempt time of a delivery the provider asked to retry later', function (): void {
    $world = OpsWorld::boot();
    $world->w->answer = static fn () => SendKit::apnsReason(503, 'ServiceUnavailable');
    $world->queue([ApnsDeviceToken::of(W::apnsToken('d'))], 'order-2');

    $summary = $world->json('mobile-push:relay');

    expect($summary['recorded'])->toBe(['deferred' => 1])
        ->and($summary['deferred']['next_attempt_at'])->toBeString()
        ->and(new DateTimeImmutable($summary['deferred']['next_attempt_at']))->toBeGreaterThan($world->w->clock->now());
    expect($world->w->transport->sentCount())->toBe(1);   // one attempt, nobody slept
});

it('refuses on the light profile and says how to fix it, sending nothing', function (): void {
    $world = OpsWorld::boot(profile: 'light');

    foreach (['mobile-push:relay', 'mobile-push:sweep'] as $command) {
        [$exit, $output] = $world->call($command);
        expect($exit)->toBe(1)
            ->and($output)->toContain('durable delivery profile')->toContain('MOBILE_PUSH_DELIVERY_PROFILE=durable');
    }
    expect($world->w->transport->sentCount())->toBe(0);
});

it('the sweeper recovers a relay that was lost: a dead worker held the lease and no job ever ran', function (): void {
    $world = OpsWorld::boot();
    $destinations = array_map(static fn (string $s) => ApnsDeviceToken::of(W::apnsToken($s)), ['x', 'y']);
    $world->queue($destinations, 'order-3');

    // A worker claimed both deliveries and died before sending: leases held, no request, relay job gone.
    $claimed = app(DatabaseLedger::class)->claimDue('default', 'dead-worker', 10);
    expect($claimed)->toHaveCount(2);
    Queue::fake();   // forget the relay job queued at intent time: the queue lost it
    $world->advance(61);   // the 60 s lease is over

    $summary = $world->json('mobile-push:sweep');

    expect($summary['leases_requeued_not_started'])->toBe(2)
        ->and($summary['ended_unknown_outcome'])->toBe(0)
        ->and($summary['scopes_visited'])->toBe(['default'])
        ->and($summary['relay_jobs_dispatched_for_scopes'])->toBe(['default']);
    Queue::assertPushed(RelayDeliveriesJob::class, static fn (RelayDeliveriesJob $job): bool => $job->effectId === null && $job->scope === 'default');
    expect($world->w->transport->sentCount())->toBe(0);

    // The relay job the sweeper handed over, run inline (what a worker would do).
    $relay = $world->json('mobile-push:relay');
    expect($relay['sent'])->toBe(2)
        ->and($world->w->requestsTo('api.push.apple.com'))->toHaveCount(2)
        ->and(DB::table('mobile_push_deliveries')->where('state', 'accepted')->count())->toBe(2);
});

it('a lease lost after the request may have left is an unknown outcome, never "not sent"', function (): void {
    $world = OpsWorld::boot();
    $world->queue([ApnsDeviceToken::of(W::apnsToken('z'))], 'order-4', PushMessage::make('Code', 'Your code')->ttl(600));
    $ledger = app(DatabaseLedger::class);
    $claimed = $ledger->claimDue('default', 'crashed-worker', 10);
    expect($ledger->markAttemptStarted($claimed[0]->lease))->toBeTrue();   // the request may have left
    $world->advance(61);

    $summary = $world->json('mobile-push:sweep');

    expect($summary['ended_unknown_outcome'] + $summary['leases_retried_after_unknown'])->toBe(1)
        ->and($world->w->transport->sentCount())->toBe(0);
});
