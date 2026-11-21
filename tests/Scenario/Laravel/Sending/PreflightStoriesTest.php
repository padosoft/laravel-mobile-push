<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once dirname(__DIR__).'/Integration/IntegrationWorld.php';

/*
 * A send that spans an iPhone and an Android phone: nothing leaves until the message has compiled for every
 * connection. A message that only one provider can carry is that provider's problem (Rejected results for its
 * destinations), never an exception thrown after the other push already went out.
 */

/** @return list<ApnsDeviceToken|FcmToken> */
function bothPhones(): array
{
    return [ApnsDeviceToken::of(W::apnsToken('iphone')), FcmToken::of('fake-fcm-token-android-phone-0001')];
}

it('a subtitle reaches the iPhone; the Android phone gets a Rejected result with the remedy; one request, no exception', function (): void {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);

    // The iPhone comes first: before the pre-flight its push left, then the Android compilation threw.
    $results = MobilePush::to(bothPhones())->send(PushMessage::make('Hi', 'x')->subtitle('s'))->retained();
    $byKind = [];
    foreach ($results as $result) {
        $byKind[$result->destination->kind()->value] = $result;
    }

    expect($byKind[DestinationKind::ApnsDeviceToken->value]->status)->toBe(SendStatus::Accepted)
        ->and($byKind[DestinationKind::FcmToken->value]->status)->toBe(SendStatus::Rejected)
        ->and($byKind[DestinationKind::FcmToken->value]->reason->code)->toBe(ReasonCode::PayloadInvalid)
        ->and($byKind[DestinationKind::FcmToken->value]->reason->hint)->toContain('subtitle')->toContain('->lenient()')
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and($world->send->requestsTo('fcm.googleapis.com'))->toBe([]);
});

it('a message invalid for both providers throws before any request', function (): void {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);

    expect(fn () => MobilePush::to(bothPhones())->send(PushMessage::make('Hi')->badge(-1)))
        ->toThrow(InvalidMessageException::class)
        ->and($world->send->pushRequests())->toBe([]);
});

it('queue(): the valid connection is queued, the other one is recorded as Rejected and queues nothing', function (): void {
    Queue::fake();
    IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]])->withDatabaseLog();

    $ids = MobilePush::to(bothPhones())->queue(PushMessage::make('Hi', 'x')->subtitle('s'));

    $jobs = Queue::pushed(SendPushJob::class);
    $rows = IntegrationWorld::logRows();
    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()->binding['name'])->toBe('ios')
        ->and(explode(',', $ids))->toHaveCount(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['binding_name'])->toBe('android')
        ->and($rows[0]['status'])->toBe('rejected')
        ->and($rows[0]['reason_code'])->toBe(ReasonCode::PayloadInvalid->value);
});

it('queue() in the durable profile: nothing is written to the ledger for the connection whose message cannot compile', function (): void {
    Queue::fake();
    IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]])->withDurableLedger();

    MobilePush::to(bothPhones())->queue(PushMessage::make('Hi', 'x')->subtitle('s'));

    $bindings = array_map(static fn (string $json): string => json_decode($json, true)['name'], DB::table('mobile_push_effects')->pluck('binding')->all());
    expect($bindings)->toBe(['ios'])
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(1);
});

it('queue(): a message invalid for both providers throws before anything is queued or written', function (): void {
    Queue::fake();
    IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]])->withDurableLedger();

    expect(fn () => MobilePush::to(bothPhones())->queue(PushMessage::make('Hi')->badge(-1)))->toThrow(InvalidMessageException::class)
        ->and(Queue::pushedJobs())->toBe([])
        ->and(DB::table('mobile_push_effects')->count())->toBe(0);
});

it('the intent fake runs the same validation: my app builds an oversized message and the faked test sees it', function (): void {
    IntegrationWorld::install(['connections' => ['ios' => W::apns()]], 'testing');
    $fake = MobilePush::fake();

    $result = MobilePush::to(ApnsDeviceToken::of(W::apnsToken('big')))->send(PushMessage::make('Hi', str_repeat('x', 6000)))->retained()[0];
    expect($result)->toBeInstanceOf(SendResult::class)
        ->and($result->status)->toBe(SendStatus::Rejected)
        ->and($result->reason->code)->toBe(ReasonCode::PayloadTooLarge);

    expect(fn () => MobilePush::to(ApnsDeviceToken::of(W::apnsToken('neg')))->send(PushMessage::make('Hi')->badge(-1)))
        ->toThrow(InvalidMessageException::class, 'badge');

    $fake->withoutValidation();
    expect(MobilePush::to(ApnsDeviceToken::of(W::apnsToken('neg')))->send(PushMessage::make('Hi')->badge(-1))->retained()[0]->status)
        ->toBe(SendStatus::Accepted);
});
