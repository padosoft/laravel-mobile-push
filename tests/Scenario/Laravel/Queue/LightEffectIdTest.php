<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Logging\LogRecordFactory;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * "What happened to effect X?" for a light-profile queue(): the effect id it returns must be the key a listener or the
 * database log sink can look the outcomes up by, for the first job and for every continuation of the same effect.
 */

it('a deferred continuation of a light job belongs to the same effect: same effect id, same Horizon tag', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $w->dispatcher->add($w->kit->fcmDriver());
    $slow = SendKit::fcmToken(2)->value();
    $w->transport->respondUsing(static fn (Request $r) => QueueWorld::fcmTokenOf($r) === $slow
        ? SendKit::fcmError(503, 'UNAVAILABLE')
        : SendKit::fcmOk());

    $effectId = app(QueuedSender::class)->queue(SendKit::fcmBinding(), PushMessage::make('Order', 'Shipped')->ttl(7200),
        [SendKit::fcmToken(1), SendKit::fcmToken(2)], SendKit::live(), new QueueOptions);
    $w->run(Queue::pushed(SendPushJob::class)->first());

    $jobs = Queue::pushed(SendPushJob::class)->values();
    expect($jobs)->toHaveCount(2)
        ->and($jobs[1]->continuation)->toBe(1)
        ->and(array_column($jobs[1]->destinations, 'v'))->toBe([$slow])
        ->and($jobs[1]->effectId)->toBe($effectId)
        ->and($jobs[1]->tags())->toContain('effect:'.$effectId);
});

/*
 * "What happened to effect X?": the id `queue()` returned is on every per-result event, on the batch event and in
 * the send-log rows of the job that sends it (SendOptions::withEffectId(), set by the light job and by the relay).
 */
it('a light queued send run on the sync queue: every result event, the batch event and the log rows carry the effect id', function (): void {
    $world = W::install(['connections' => ['ios' => W::apns()], 'delivery.profile' => 'light', 'log.sinks' => 'database', 'log.database.mode' => 'all']);
    config()->set('queue.default', 'sync');
    (require dirname(__DIR__, 4).'/database/migrations/2026_11_20_000050_create_mobile_push_logs_table.php')->up();
    app()->forgetInstance(SinkManager::class);
    app()->forgetInstance(LogRecordFactory::class);

    $effectId = MobilePush::to([ApnsDeviceToken::of(W::apnsToken('one')), ApnsDeviceToken::of(W::apnsToken('two'))])
        ->queue(PushMessage::make('Later'));
    app(SinkManager::class)->flush();

    $results = $world->eventsOf(PushResultProduced::class);
    $batch = $world->eventsOf(PushBatchCompleted::class);
    expect($results)->toHaveCount(2)
        ->and(array_map(static fn (PushResultProduced $e): ?string => $e->effectId, $results))->toBe([$effectId, $effectId])
        ->and($batch)->toHaveCount(1)
        ->and($batch[0]->effectId)->toBe($effectId)
        ->and(DB::table('mobile_push_logs')->pluck('effect_id')->all())->toBe([$effectId, $effectId]);
});

it('the durable relay does the same: the events and the log rows of a relayed effect carry its id', function (): void {
    $world = W::install(['connections' => ['ios' => W::apns()], 'delivery.profile' => 'durable', 'log.sinks' => 'database', 'log.database.mode' => 'all']);
    config()->set('queue.default', 'sync');
    foreach ([...LedgerModule::migrationPaths(), dirname(__DIR__, 4).'/database/migrations/2026_11_20_000050_create_mobile_push_logs_table.php'] as $migration) {
        (require $migration)->up();
    }
    app()->forgetInstance(SinkManager::class);
    app()->forgetInstance(LogRecordFactory::class);

    $effectId = MobilePush::to([ApnsDeviceToken::of(W::apnsToken('one')), ApnsDeviceToken::of(W::apnsToken('two'))])
        ->queue(PushMessage::make('Later'));
    app(SinkManager::class)->flush();

    $results = $world->eventsOf(PushResultProduced::class);
    $batch = $world->eventsOf(PushBatchCompleted::class);
    expect(DB::table('mobile_push_deliveries')->where('effect_id', $effectId)->count())->toBe(2)
        ->and($results)->toHaveCount(2)
        ->and(array_map(static fn (PushResultProduced $e): ?string => $e->effectId, $results))->toBe([$effectId, $effectId])
        ->and($batch)->toHaveCount(1)
        ->and($batch[0]->effectId)->toBe($effectId)
        ->and(DB::table('mobile_push_logs')->pluck('effect_id')->all())->toBe([$effectId, $effectId]);
});
