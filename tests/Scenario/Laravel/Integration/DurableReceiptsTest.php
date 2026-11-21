<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptFunnelReader;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * "Durable send with receipts, end to end": queue() on the durable profile writes the intent to the ledger, the relay
 * sends it with an `mp_r` marker in the payload, the app POSTs the marker back, the delivery shows the client event
 * and the funnel counts it. The marker never appears in the send log.
 */
it('queues durably, sends one marker, records the receipt the app reports, and never logs the marker', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]])
        ->withDatabaseLog()
        ->withDurableLedger(receipts: true);
    $token = W::apnsToken('durable-phone');

    $effectId = MobilePush::connection('ios')->to(ApnsDeviceToken::of($token))->queue(PushMessage::make('Your order', 'is on its way'));

    // The relay ran (sync queue) and sent one request carrying the marker.
    $requests = $world->send->requestsTo('api.push.apple.com');
    expect($requests)->toHaveCount(1);
    $marker = IntegrationWorld::body($requests[0])['mp_r'] ?? null;
    expect($marker)->toBeString()->not->toBe('')
        ->and(DB::table('mobile_push_deliveries')->where('effect_id', $effectId)->value('state'))->toBe('accepted')
        ->and((int) DB::table('mobile_push_deliveries')->where('effect_id', $effectId)->value('receipt_marker'))->toBe(1);

    // The app reports the push as received, then opened.
    $this->postJson('/mobile-push/receipts', ['marker' => $marker, 'event' => 'received'])->assertNoContent();
    $this->postJson('/mobile-push/receipts', ['marker' => $marker, 'event' => 'opened'])->assertNoContent();

    $delivery = DB::table('mobile_push_deliveries')->where('effect_id', $effectId)->first();
    $funnel = app(ReceiptFunnelReader::class)->forEffect('default', $effectId);
    expect($delivery->client_received_at)->not->toBeNull()
        ->and($delivery->client_opened_at)->not->toBeNull()
        ->and($funnel->accepted)->toBe(1)
        ->and($funnel->acceptedWithMarker)->toBe(1)
        ->and($funnel->reportedReceived)->toBe(1)
        ->and($funnel->reportedOpened)->toBe(1);

    // The send log has the push but never the marker, the token or the bearer.
    $rows = IntegrationWorld::logRows();
    $dump = json_encode($rows, JSON_THROW_ON_ERROR);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('accepted')
        ->and($dump)->not->toContain($marker)
        ->and($dump)->not->toContain($token)
        ->and($dump)->not->toContain(substr($requests[0]->headers['authorization'], 7));
});

it('a synchronous send on the durable profile writes no ledger row and carries no marker', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]])->withDurableLedger(receipts: true);

    W::all(MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('sync-phone')))->send(PushMessage::make('Now')));

    expect($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and(IntegrationWorld::body($world->send->requestsTo('api.push.apple.com')[0]))->not->toHaveKey('mp_r')
        ->and(DB::table('mobile_push_effects')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(0);
});

it('redacts the marker from the send log even when the provider echoes it back', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]])
        ->withDatabaseLog()
        ->withDurableLedger(receipts: true);
    $echoed = [];
    $world->send->answer = static function (Request $request) use (&$echoed): Response {
        $echoed[] = $marker = (string) (IntegrationWorld::body($request)['mp_r'] ?? '');

        return new Response(400, [], (string) json_encode(['reason' => 'BadPayload '.$marker]));
    };

    MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('echo-phone')))->queue(PushMessage::make('Hi'));

    $rows = IntegrationWorld::logRows();
    expect($echoed)->toHaveCount(1)->and($echoed[0])->not->toBe('')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('rejected')
        ->and((string) $rows[0]['reason_raw'])->toBe('BadPayload [redacted:secret]')
        ->and(json_encode($rows, JSON_THROW_ON_ERROR))->not->toContain($echoed[0]);
});
