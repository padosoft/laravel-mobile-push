<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: support turns on payload logging (`log.payload = full`) to debug a deep link that does not open. The log
 * sink reads AttemptReport::payload(). It must see the payload the app built, and none of the attempt's secrets:
 * not the bearer, not the receipt marker, not the device token, even when the app put them in its own data.
 */
it('hands an observer the compiled payload of the attempt with the device token and receipt marker removed', function () {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $transport = new FakeTransport;
    $token = SendKit::apnsToken(7);
    $message = PushMessage::make('Hi', 'There')->data(['echo' => 'device '.$token->value(), 'screen' => 'orders']);

    SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->apnsDriver(), $message, [$token], SendKit::live(receiptMarker: fn (Destination $d, string $id): string => 'rcpt-marker-0001')));

    $wire = json_decode($transport->requests()[0]->body, true);
    $payload = $observer->attempts[0]->payload();
    expect($wire['echo'])->toContain($token->value())                     // the app really sent it
        ->and($wire['mp_r'])->toBe('rcpt-marker-0001')
        ->and($payload)->toBeArray()
        ->and($payload['aps'])->toBe($wire['aps'])
        ->and($payload['screen'])->toBe('orders')
        ->and($payload['echo'])->toStartWith('device ')->not->toContain($token->value())
        ->and($payload['mp_r'])->not->toContain('rcpt-marker-0001')
        ->and($observer->attempts[0]->payload())->toBe($payload);         // computed again, same answer
});

/*
 * Story: Google echoes the access token in an error message (a proxy in front of it does). The diagnostics of the
 * attempt must not carry it, in the response body nor in a transport failure message.
 */
it('removes the bearer and the receipt marker of the attempt from the response and failure diagnostics', function () {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $bearer = 'ya29.fake-access-token-one';
    $marker = 'rcpt-marker-0002';
    $transport = (new FakeTransport)->push(
        new Response(400, [], (string) json_encode(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => "token {$bearer} marker {$marker} refused"]])),
        new TransportFailure(TransportEvidence::Unknown, 56, "proxy reset after Authorization: {$bearer}, marker {$marker}"),
    );

    $results = SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->fcmDriver(), PushMessage::make('A', 'B'), [SendKit::fcmToken(1), SendKit::fcmToken(2)], SendKit::live(receiptMarker: fn () => $marker)));

    [$refused, $lost] = $observer->attempts;
    expect($results[0]->status)->toBe(SendStatus::Rejected)
        ->and($results[1]->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($refused->response)->toBeArray()
        ->and($refused->response['status'])->toBe(400)
        ->and($refused->response['body'])->toContain('refused')->not->toContain($bearer)->not->toContain($marker)
        ->and($refused->failure)->toBeNull()
        ->and($lost->response)->toBeNull()
        ->and($lost->failure['evidence'])->toBe('unknown')
        ->and($lost->failure['errno'])->toBe(56)
        ->and($lost->failure['request_written'])->toBeTrue()
        ->and($lost->failure['message'])->toStartWith('proxy reset')->not->toContain($bearer)->not->toContain($marker);
});

it('describes each attempt with its number, credential generation, connection pool and decision', function () {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $transport = (new FakeTransport)->push(SendKit::fcmError(503, 'UNAVAILABLE', 'UNAVAILABLE'));

    [$result] = SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->fcmDriver(), PushMessage::make('A', 'B'), [SendKit::fcmToken(1)], SendKit::live()));
    $attempt = $observer->attempts[0];

    expect($result->status)->toBe(SendStatus::Deferred)
        ->and($attempt->attempt)->toBe(1)
        ->and($attempt->credentialGeneration)->toBe(0)
        ->and($attempt->poolKey)->toBe($transport->recorded()[0]['profile']->poolKey)
        ->and($attempt->decision->reason->code)->toBe($result->reason->code)
        ->and($attempt->__debugInfo())->toBe([
            'binding' => SendKit::fcmBinding()->toArray(),
            'attempt' => 1,
            'credentialGeneration' => 0,
            'decision' => $attempt->decision->status->value,
            'request' => $attempt->request,
            'response' => $attempt->response,
            'failure' => null,
            'durationMs' => $attempt->durationMs,
        ])
        ->and($attempt->request['headers']['authorization'] ?? null)->not->toContain('ya29');
});
