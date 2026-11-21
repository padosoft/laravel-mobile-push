<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\InteractsWithMobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\Shopper;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithMobilePush::class);

it('shows the mobile team exactly what APNs receives', function (): void {
    $wire = MobilePush::fakeTransport();

    $report = MobilePush::to(new Shopper(7))->send(PushMessage::make('Order shipped', 'It arrives on Friday')->data(['order' => '42']));

    expect($report->allAccepted())->toBeTrue();
    $wire->assertRequestCount(1);
    $wire->assertSentToHost('api.sandbox.push.apple.com');
    $wire->assertApnsRequest(fn (array $headers, array $payload, string $url): bool => $headers['apns-push-type'] === 'alert'
        && $headers['apns-topic'] === 'com.example.fake'
        && $payload['aps']['alert']['title'] === 'Order shipped'
        && $payload['order'] === '42'
        && str_ends_with($url, '/3/device/'.Shopper::apnsToken(7)));
    $wire->assertApnsRequest(fn (array $h): bool => $h['authorization'] === '[redacted]', 1);
});

it('shows the mobile team exactly what FCM receives, and what validate-only sends', function (): void {
    $wire = MobilePush::fakeTransport();

    MobilePush::to(new Shopper(1, android: true))->send(PushMessage::make('Order shipped', 'Soon'));
    $report = MobilePush::to(new Shopper(2, android: true))->validateOnly()->send(PushMessage::make('Dry run'));

    expect($report->countOf(SendStatus::Validated))->toBe(1);
    $wire->assertRequestCount(2); // the Google OAuth exchange is not a push request
    $wire->assertSentToHost('fcm.googleapis.com');
    $wire->assertFcmRequest(fn (array $message, bool $validateOnly): bool => ! $validateOnly && $message['notification']['title'] === 'Order shipped', 1);
    $wire->assertFcmRequest(fn (array $message, bool $validateOnly): bool => $validateOnly && $message['notification']['title'] === 'Dry run', 1);
});

it('lets APNs retry handling be tested: a 503 is deferred and holds back the provider, as the real pipeline dictates', function (): void {
    $wire = MobilePush::fakeTransport();
    $wire->sequence([$wire->apnsError(503, 'ServiceUnavailable'), $wire->apnsAccepted()]);

    $first = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'))->retained()[0];
    $second = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'))->retained()[0];

    expect($first->status)->toBe(SendStatus::Deferred)
        ->and($first->reason->code)->toBe(ReasonCode::ProviderUnavailable)
        ->and($first->nextAttemptAt)->not->toBeNull()
        ->and($first->attempts)->toBe(1)
        // The provider-wide throttle opened by the 503 holds the next send back without touching the wire.
        ->and($second->status)->toBe(SendStatus::Deferred)
        ->and($second->attempts)->toBe(0);
    $wire->assertRequestCount(1);
});

it('reports an uninstalled app from a real 410 answer', function (): void {
    $wire = MobilePush::fakeTransport();
    $wire->always($wire->apnsError(410, 'Unregistered', 1_700_000_000_000));

    $result = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'))->retained()[0];

    expect($result->status)->toBe(SendStatus::Rejected)
        ->and($result->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($result->invalidatedAtMs)->toBe(1_700_000_000_000);
});

it('classifies an FCM UNREGISTERED answer and honours Retry-After on UNAVAILABLE', function (): void {
    $wire = MobilePush::fakeTransport();
    $wire->sequence([$wire->fcmError(404, 'UNREGISTERED'), $wire->fcmError(503, 'UNAVAILABLE', 120)]);

    $gone = MobilePush::to(new Shopper(1, android: true))->send(PushMessage::make('Hi'))->retained()[0];
    $busy = MobilePush::to(new Shopper(2, android: true))->send(PushMessage::make('Hi'))->retained()[0];

    expect($gone->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($busy->status)->toBe(SendStatus::Deferred)
        ->and($busy->nextAttemptAt)->not->toBeNull();
});

it('treats a lost connection as an unknown outcome, not a failure', function (): void {
    $wire = MobilePush::fakeTransport();
    $wire->always($wire->connectionLost());

    $report = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'));

    expect($report->hasUnknown())->toBeTrue();
});

it('treats a connection that could not even be opened as not sent', function (): void {
    $wire = MobilePush::fakeTransport();
    $wire->always($wire->notSent());

    $result = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'))->retained()[0];

    expect($result->status)->not->toBe(SendStatus::OutcomeUnknown)
        ->and($result->reason->code)->toBe(ReasonCode::TransportNotSent);
});

it('answers per request with respondUsing', function (): void {
    $wire = MobilePush::fakeTransport();
    $wire->respondUsing(fn (Request $r) => str_contains($r->url, Shopper::apnsToken(9)) ? $wire->apnsError(400, 'BadDeviceToken') : null);

    $ok = MobilePush::to(new Shopper(8))->send(PushMessage::make('Hi'))->retained()[0];
    $bad = MobilePush::to(new Shopper(9))->send(PushMessage::make('Hi'))->retained()[0];

    expect($ok->status)->toBe(SendStatus::Accepted)
        ->and($bad->reason->code)->toBe(ReasonCode::DestinationRejected);
});

it('decodes what was sent for tests that want to look at it themselves', function (): void {
    $wire = MobilePush::fakeTransport();
    MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'));
    MobilePush::to(new Shopper(1, android: true))->send(PushMessage::make('Hi'));

    expect($wire->requests())->toHaveCount(2)
        ->and($wire->apnsRequests())->toHaveCount(1)
        ->and($wire->apnsRequests()[0]['payload']['aps']['alert']['title'])->toBe('Hi')
        ->and($wire->fcmRequests())->toHaveCount(1)
        ->and($wire->fcmRequests()[0]['validateOnly'])->toBeFalse();
});

it('never shows a bearer token or a device token in a failure message', function (): void {
    $wire = MobilePush::fakeTransport();
    MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi'));
    MobilePush::to(new Shopper(1, android: true))->send(PushMessage::make('Hi'));
    $bearer = substr($wire->requests()[0]->headers['authorization'], 7);

    foreach ([
        fn () => $wire->assertApnsRequest(fn (): bool => false),
        fn () => $wire->assertFcmRequest(fn (): bool => false),
        fn () => $wire->assertRequestCount(5),
        fn () => $wire->assertSentToHost('example.org'),
    ] as $failing) {
        try {
            $failing();
            $this->fail('the assertion should have failed');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())
                ->not->toContain($bearer)
                ->not->toContain(Shopper::apnsToken(7))
                ->not->toContain('fcm-token-1-');
        }
    }

    try {
        $wire->assertApnsRequest(fn (): bool => false);
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('No APNs request matched')->toContain('[redacted]')->toContain('apns-topic');
    }
});

it('gives the same pipeline the app gets in production: oversize payloads are rejected before any request', function (): void {
    $wire = MobilePush::fakeTransport();

    $result = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi', str_repeat('x', 6000)))->retained()[0];

    expect($result->status)->toBe(SendStatus::Rejected)
        ->and($result->reason->code)->toBe(ReasonCode::PayloadTooLarge);
    $wire->assertNoRequests();
});

it('validates with the real compilers under the intent fake too: the same oversize send is rejected, before any byte', function (): void {
    $push = MobilePush::fake();

    $result = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi', str_repeat('x', 6000)))->retained()[0];

    expect($result->status)->toBe(SendStatus::Rejected)
        ->and($result->reason->code)->toBe(ReasonCode::PayloadTooLarge);
});

it('records the oversize send as accepted when the test opts out of validation', function (): void {
    $push = MobilePush::fake()->withoutValidation();

    $result = MobilePush::to(new Shopper(7))->send(PushMessage::make('Hi', str_repeat('x', 6000)))->retained()[0];

    expect($result->status)->toBe(SendStatus::Accepted);
    $push->assertSentTimes(1);
});

it('works with explicit typed destinations on a configured connection too', function (): void {
    $wire = MobilePush::fakeTransport();

    MobilePush::connection('ios')->to(ApnsDeviceToken::of(Shopper::apnsToken(3)))->send(PushMessage::make('Hi'));
    MobilePush::connection('android')->to(FcmToken::of('fcm-token-3-'.str_repeat('x', 40)))->send(PushMessage::make('Hi'));

    $wire->assertRequestCount(2);
});
