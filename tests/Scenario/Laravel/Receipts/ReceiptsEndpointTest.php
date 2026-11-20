<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Ledger\ClientEvent;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Receipts\ClientEvidence;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Padosoft\MobilePush\Laravel\Receipts\Events\PushOpenedReported;
use Padosoft\MobilePush\Laravel\Receipts\Events\PushReceivedReported;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptFunnelReader;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptReport;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Receipts\ReceiptsWorld as World;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

const RECEIPTS_URL = '/mobile-push/receipts';
const GENERIC = ['message' => 'The receipt was not accepted.'];

/** @return list<object> */
function captureEvents(): array
{
    $captured = new ArrayObject;
    foreach ([PushReceivedReported::class, PushOpenedReported::class] as $class) {
        Event::listen($class, static function (object $event) use ($captured): void {
            $captured[] = $event;
        });
    }

    return [$captured];
}

it('the app reports it: received then opened, both recorded, two events, the funnel shows 1/1/1', function (): void {
    $w = World::boot();
    [$events] = captureEvents();
    $sent = $w->send(H::token(1));
    $w->clock->advance(3);

    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'received', 'at' => $w->clock->now()->format(DATE_ATOM)])->assertNoContent();
    $w->clock->advance(20);
    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'opened'])->assertNoContent();

    $delivery = $w->ledger->delivery('default', $sent['deliveryId']);
    $funnel = app(ReceiptFunnelReader::class)->forEffect('default', $sent['effectId']);

    expect($delivery->clientReceivedAt?->format('H:i:s'))->toBe('10:00:03')
        ->and($delivery->clientOpenedAt?->format('H:i:s'))->toBe('10:00:23')
        ->and($delivery->state)->toBe(DeliveryState::Accepted)
        ->and($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(PushReceivedReported::class)
        ->and($events[1])->toBeInstanceOf(PushOpenedReported::class)
        ->and($events[0]->destinationKind)->toBe(DestinationKind::ApnsDeviceToken)
        ->and($events[0]->deliveryId)->toBe($sent['deliveryId'])
        ->and([$funnel->accepted, $funnel->reportedReceived, $funnel->reportedOpened, $funnel->acceptedWithMarker])->toBe([1, 1, 1, 1]);
});

it('reported twice and out of order: each event is recorded once, counters never double, always 204', function (): void {
    $w = World::boot();
    [$events] = captureEvents();
    $sent = $w->send(H::token(1));

    $first = $w->clock->now()->modify('+2 seconds')->format(DATE_ATOM);
    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'opened', 'at' => $first])->assertNoContent();
    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'received'])->assertNoContent();
    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'opened', 'at' => $w->clock->now()->modify('+9 seconds')->format(DATE_ATOM)])->assertNoContent();
    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'received'])->assertNoContent();

    $delivery = $w->ledger->delivery('default', $sent['deliveryId']);
    $funnel = $w->store()->effectFunnel('default', $sent['effectId']);

    expect($events)->toHaveCount(2)
        ->and($delivery->clientOpenedAt?->getTimestamp())->toBe($w->clock->now()->modify('+0 seconds')->getTimestamp())   // clamped to server now, the first report wins
        ->and([$funnel->reportedReceived, $funnel->reportedOpened])->toBe([1, 1]);
});

it('nobody reported: accepted 1, received 0 is "not observed" and nothing says "not delivered"', function (): void {
    $w = World::boot();
    $sent = $w->send(H::token(1));
    $funnel = $w->store()->effectFunnel('default', $sent['effectId']);

    expect($funnel->accepted)->toBe(1)
        ->and($funnel->acceptedWithMarker)->toBe(1)
        ->and($funnel->reportedReceived)->toBe(0)
        ->and($funnel->receivedNotObserved())->toBe(1)
        ->and($funnel->receivedRate())->toBe(0.0)
        ->and(strtolower(json_encode($funnel->toArray(), JSON_THROW_ON_ERROR)))->not->toContain('not_delivered')->not->toContain('undelivered')
        ->and(array_keys($funnel->toArray()))->toContain('received_not_observed');
});

it('a topic push has no individual receipts: no marker for topic, condition or broadcast destinations', function (): void {
    $w = World::boot();
    $id = '00000000-0000-4000-8000-000000000001';

    expect($w->issuer()->issue('default', H::topic('news'), $id))->toBeNull()
        ->and($w->issuer()->issue('default', FcmCondition::of("'a' in topics && 'b' in topics"), $id))->toBeNull()
        ->and($w->issuer()->issue('default', ApnsBroadcastChannel::of('dMjapFcAEfAAAAAAAAAAAA=='), $id))->toBeNull()
        ->and($w->issuer()->issue('default', H::token(1), $id))->toBeString()
        ->and($w->issuer()->callback('default', false)(H::token(1), $id))->toBeNull();
});

it('a stolen or forged marker: every failure is the same rejection and nothing is recorded', function (): void {
    $w = World::boot();
    $mine = $w->send(H::token(1), scope: 'tenant-a');
    $other = $w->send(H::token(2), scope: 'tenant-b');
    $marker = app(ReceiptMarker::class);
    $good = $mine['marker'];
    $flipped = $good;
    $flipped[strlen($flipped) - 2] = $flipped[strlen($flipped) - 2] === 'A' ? 'B' : 'A';
    $foreignKey = new ReceiptMarker(new KeyRing([1 => random_bytes(32)]));
    $expired = $marker->issue('tenant-a', $mine['messageId'], $w->clock->now()->modify('-1 second'));
    // Tenant A's marker re-targeted at tenant B's delivery: A's scope + B's message id. Authentic, yet it points into A.
    $crossTenant = $marker->issue('tenant-a', $other['messageId'], $w->clock->now()->modify('+1 day'));
    $unknownDelivery = $marker->issue('tenant-a', '12345678-1234-4234-8234-123456789abc', $w->clock->now()->modify('+1 day'));

    foreach ([$flipped, $foreignKey->issue('tenant-a', $mine['messageId'], $w->clock->now()->modify('+1 day')), $expired, $crossTenant, $unknownDelivery, 'garbage', str_repeat('A', 300), substr($good, 0, 20)] as $bad) {
        $this->postJson(RECEIPTS_URL, ['marker' => $bad, 'event' => 'received'])->assertStatus(422)->assertExactJson(GENERIC);
    }

    expect($w->store()->effectFunnel('tenant-a', $mine['effectId'])->reportedReceived)->toBe(0)
        ->and($w->store()->effectFunnel('tenant-b', $other['effectId'])->reportedReceived)->toBe(0);

    // The genuine markers still work, each inside its own tenant only.
    $this->postJson(RECEIPTS_URL, ['marker' => $good, 'event' => 'received'])->assertNoContent();
    expect($w->store()->effectFunnel('tenant-a', $mine['effectId'])->reportedReceived)->toBe(1)
        ->and($w->store()->effectFunnel('tenant-b', $other['effectId'])->reportedReceived)->toBe(0);
});

it('malformed requests get the same generic rejection', function (): void {
    $w = World::boot(['max_batch' => 2]);
    $sent = $w->send(H::token(1));
    $m = $sent['marker'];

    foreach ([
        [],
        ['marker' => $m],
        ['marker' => $m, 'event' => 'delivered'],
        ['marker' => $m, 'event' => 'received', 'at' => 'yesterday'],
        ['marker' => ['x'], 'event' => 'received'],
        [['marker' => $m, 'event' => 'received'], ['marker' => $m, 'event' => 'opened'], ['marker' => $m, 'event' => 'opened']],
    ] as $body) {
        $this->postJson(RECEIPTS_URL, $body)->assertStatus(422)->assertExactJson(GENERIC);
    }
    expect($w->store()->effectFunnel('default', $sent['effectId'])->reportedReceived)->toBe(0);
});

it('a small batch is accepted, and one bad item rejects the call but keeps the good ones', function (): void {
    $w = World::boot();
    $a = $w->send(H::token(1));
    $b = $w->send(H::token(2));

    $this->postJson(RECEIPTS_URL, [['marker' => $a['marker'], 'event' => 'received'], ['marker' => $b['marker'], 'event' => 'opened']])->assertNoContent();
    $this->postJson(RECEIPTS_URL, ['receipts' => [['marker' => $a['marker'], 'event' => 'opened'], ['marker' => 'nope', 'event' => 'opened']]])->assertStatus(422);

    expect($w->ledger->delivery('default', $a['deliveryId'])?->clientOpenedAt)->not->toBeNull()
        ->and($w->ledger->delivery('default', $b['deliveryId'])?->clientReceivedAt)->toBeNull()
        ->and($w->ledger->delivery('default', $b['deliveryId'])?->clientOpenedAt)->not->toBeNull();
});

it('the response was lost but the phone got it: the unknown outcome gains the client event, keeps its provider outcome', function (): void {
    $w = World::boot();
    [$events] = captureEvents();
    $sent = $w->send(H::token(1), SendStatus::OutcomeUnknown);
    $before = $w->ledger->delivery('default', $sent['deliveryId']);
    expect($before->state)->toBe(DeliveryState::OutcomeUnknown)->and(ClientEvidence::acceptedOrClientConfirmed($before))->toBeFalse();

    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'received'])->assertNoContent();

    $after = $w->ledger->delivery('default', $sent['deliveryId']);
    $funnel = $w->store()->effectFunnel('default', $sent['effectId']);
    expect($after->state)->toBe(DeliveryState::OutcomeUnknown)
        ->and($after->reason->code)->toBe($before->reason->code)
        ->and($after->clientReceivedAt)->not->toBeNull()
        ->and(ClientEvidence::acceptedOrClientConfirmed($after))->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and([$funnel->accepted, $funnel->unknownOutcome, $funnel->unknownClientConfirmed, $funnel->unknownReportedReceived])->toBe([0, 1, 1, 1])
        ->and($funnel->acceptedOrClientConfirmed())->toBe(1);
});

it('a marker issued before ledger-assigned ids (message id derived from the delivery ULID) still finds its delivery', function (): void {
    $w = World::boot();
    $sent = $w->send(H::token(1), legacyDerivedId: true);

    $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => 'received'])->assertNoContent();

    expect($w->ledger->delivery('default', $sent['deliveryId'])?->clientReceivedAt)->not->toBeNull();
});

it('flooding: more than the rate from one IP, or for one marker, gets 429 and records nothing extra', function (): void {
    $w = World::boot(['rate_limit.per_ip' => 3, 'rate_limit.per_marker' => 100]);
    $sent = [];
    for ($i = 1; $i <= 5; $i++) {
        $sent[$i] = $w->send(H::token($i));
    }
    $statuses = [];
    for ($i = 1; $i <= 5; $i++) {
        $statuses[] = $this->postJson(RECEIPTS_URL, ['marker' => $sent[$i]['marker'], 'event' => 'received'])->getStatusCode();
    }

    expect($statuses)->toBe([204, 204, 204, 429, 429])
        ->and($w->ledger->delivery('default', $sent[4]['deliveryId'])?->clientReceivedAt)->toBeNull()
        ->and($w->ledger->delivery('default', $sent[5]['deliveryId'])?->clientReceivedAt)->toBeNull();
});

it('flooding one marker: past the per-marker rate the answer is 429', function (): void {
    $w = World::boot(['rate_limit.per_marker' => 2, 'rate_limit.per_ip' => 1000]);
    $sent = $w->send(H::token(1));
    $statuses = [];
    foreach (['received', 'received', 'opened', 'opened'] as $event) {
        $statuses[] = $this->postJson(RECEIPTS_URL, ['marker' => $sent['marker'], 'event' => $event])->getStatusCode();
    }

    expect($statuses)->toBe([204, 204, 429, 429])
        ->and($w->ledger->delivery('default', $sent['deliveryId'])?->clientOpenedAt)->toBeNull();
});

it('module off, or light profile: no route, the issuer returns null, nothing breaks', function (bool $enabled, bool $durable): void {
    $w = World::boot(enabled: $enabled, durable: $durable);
    $destination = H::token(1);
    $id = '00000000-0000-4000-8000-000000000001';

    expect($w->issuer()->issue('default', $destination, $id))->toBeNull()
        ->and($w->issuer()->callback('default')($destination, $id))->toBeNull()
        ->and(Route::has('mobile-push.receipts.store'))->toBeFalse();
    $this->postJson(RECEIPTS_URL, ['marker' => 'x', 'event' => 'received'])->assertNotFound();
})->with([
    'disabled' => [false, true],
    'light profile' => [true, false],
    'both' => [false, false],
]);

it('canary: the marker value is nowhere in events, responses, exceptions or a serialized delivery', function (): void {
    $w = World::boot(['rate_limit.per_marker' => 1]);
    [$events] = captureEvents();
    $sent = $w->send(H::token(1));
    $marker = $sent['marker'];
    $seen = [];

    $seen[] = $this->postJson(RECEIPTS_URL, ['marker' => $marker, 'event' => 'received'])->getContent();   // 204
    $seen[] = $this->postJson(RECEIPTS_URL, ['marker' => $marker, 'event' => 'opened'])->getContent();      // 429
    $seen[] = $this->postJson(RECEIPTS_URL, ['marker' => $marker.'x', 'event' => 'opened'])->getContent();  // 422
    $seen[] = json_encode($this->postJson(RECEIPTS_URL, ['marker' => $marker.'x', 'event' => 'opened'])->headers->all(), JSON_THROW_ON_ERROR);
    foreach ($events as $event) {
        $seen[] = serialize($event).var_export($event, true).json_encode($event, JSON_THROW_ON_ERROR);
    }
    $seen[] = serialize($w->ledger->delivery('default', $sent['deliveryId']));
    $seen[] = print_r($w->ledger->delivery('default', $sent['deliveryId']), true);
    $seen[] = json_encode($w->store()->effectFunnel('default', $sent['effectId'])->toArray(), JSON_THROW_ON_ERROR);
    ob_start();
    var_dump(new ReceiptReport($marker, ClientEvent::Received, null));
    $seen[] = (string) ob_get_clean();
    try {
        app(ReceiptMarker::class)->issue('default', 'not-a-uuid', $w->clock->now());
    } catch (InvalidArgumentException $e) {
        $seen[] = $e->getMessage().$e->getTraceAsString();
    }

    expect($events)->toHaveCount(1);
    foreach ($seen as $text) {
        expect(str_contains($text, $marker))->toBeFalse();
    }
});
