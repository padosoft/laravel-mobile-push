<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\InteractsWithMobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;
use Padosoft\MobilePush\Laravel\Testing\SwitchableTransport;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\OrderNotifications;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\Shopper;

uses(InteractsWithMobilePush::class);

it('records the order notification my controller sends, with no credentials configured', function (): void {
    config()->set('mobile-push.connections', []);
    $push = MobilePush::fake();
    $shopper = new Shopper(7);

    $report = (new OrderNotifications)->shipped($shopper);

    expect($report?->allAccepted())->toBeTrue();
    $push->assertSentTo($shopper, fn (SentPush $p): bool => $p->message instanceof PushMessage && $p->message->title === 'Order shipped');
    $push->assertSent(PushMessage::class, 1);
    $push->assertSentTimes(1);
    $push->assertSentOnConnection('ios');
    $push->assertSentInScope('default');
    $push->assertNotQueued();

    $recorded = $push->sent()->first();
    expect($recorded->connection)->toBe('ios')
        ->and($recorded->destination->value())->toBe(Shopper::apnsToken(7))
        ->and($recorded->result?->status)->toBe(SendStatus::Accepted)
        ->and($recorded->options->validateOnly)->toBeFalse()
        ->and($recorded->queued)->toBeFalse();
});

it('records the options the application chose', function (): void {
    $push = MobilePush::fake();
    $expires = new DateTimeImmutable('2030-01-01T00:00:00Z');

    MobilePush::to(new Shopper(1, android: true))->bulk()->validateOnly()->effectKey('order-42')->expiresAt($expires)
        ->send(PushMessage::make('Hi'));

    $p = $push->sent()->sole();
    expect($p->connection)->toBe('android')
        ->and($p->options->validateOnly)->toBeTrue()
        ->and($p->options->trafficClass->value)->toBe('bulk')
        ->and($p->options->effectKey)->toBe('order-42')
        ->and($p->options->expiresAt?->getTimestamp())->toBe($expires->getTimestamp())
        ->and($p->result?->status)->toBe(SendStatus::Validated);
});

it('records pushes to a topic', function (): void {
    $push = MobilePush::fake();

    MobilePush::toTopic('flash-sale')->send(PushMessage::make('Sale'));

    $push->assertSentToTopic('flash-sale');
    $push->assertSentOnConnection('android');
});

it('lets me assert that nothing was sent when the cart is empty, with a readable failure', function (): void {
    $push = MobilePush::fake();
    (new OrderNotifications)->shipped(new Shopper(7), itemsInCart: 0);
    $push->assertNothingSent();
    $push->assertNotSent(PushMessage::class);

    (new OrderNotifications)->shipped(new Shopper(7), itemsInCart: 2);

    try {
        $push->assertNothingSent();
        $this->fail('assertNothingSent() should have failed');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('No push was expected, but 1 were sent')
            ->toContain('PushMessage "Order shipped"')
            ->toContain('apns_device_token:')
            ->not->toContain(Shopper::apnsToken(7));
    }
});

it('explains which pushes WERE sent when the expected one was not', function (): void {
    $push = MobilePush::fake();
    (new OrderNotifications)->shipped(new Shopper(7));

    try {
        $push->assertSentTo(new Shopper(8));
        $this->fail('should have failed');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('No matching push was sent to')->toContain('Pushes sent:')->toContain('Order shipped');
    }
    try {
        $push->assertSent(fn (SentPush $p): bool => $p->message instanceof PushMessage && $p->message->title === 'Other', 2);
        $this->fail('should have failed');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('expected 2 time(s) sent, but 0 matched');
    }
});

it('runs my own handler when the app was uninstalled', function (): void {
    $push = MobilePush::fake();
    $gone = new Shopper(9);
    $push->failFor($gone, ReasonCode::DestinationUnregistered);
    $notifications = new OrderNotifications;

    $notifications->shipped($gone);
    $notifications->shipped(new Shopper(10));

    expect($notifications->uninstalled)->toBe([9]);
    $result = $push->sent(fn (SentPush $p): bool => $p->destination->value() === Shopper::apnsToken(9))->sole()->result;
    expect($result?->status)->toBe(SendStatus::Rejected)
        ->and($result?->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($result?->destinationAction->value)->toBe('invalidate');
});

it('runs my own handler for an unknown outcome, and for a deferred one', function (): void {
    $push = MobilePush::fake();
    $push->unknownFor(fn (SentPush $p): bool => $p->destination->value() === Shopper::apnsToken(3));
    $push->failFor(fn (SentPush $p): bool => $p->destination->value() === Shopper::apnsToken(4), ReasonCode::ProviderUnavailable, SendStatus::Deferred);
    $notifications = new OrderNotifications;

    $unknown = $notifications->shipped(new Shopper(3));
    $deferred = $notifications->shipped(new Shopper(4));

    expect($notifications->unknown)->toBe(1)
        ->and($unknown?->hasUnknown())->toBeTrue()
        ->and($deferred?->countOf(SendStatus::Deferred))->toBe(1);
});

it('answers a sequence in order, then falls back to the default', function (): void {
    $push = MobilePush::fake();
    $push->sequence([SendStatus::Failed, SendStatus::Accepted]);

    $statuses = [];
    foreach ([1, 2, 3] as $id) {
        $statuses[] = MobilePush::to(new Shopper($id))->send(PushMessage::make('x'))->retained()[0]->status;
    }

    expect($statuses)->toBe([SendStatus::Failed, SendStatus::Accepted, SendStatus::Accepted]);
});

it('refuses statuses that are decided before the provider', function (): void {
    $push = MobilePush::fake();

    expect(fn () => $push->respondWith(SendStatus::Skipped))->toThrow(InvalidArgumentException::class, 'decided before the provider');
});

it('tells me the push was queued, not sent', function (): void {
    $push = MobilePush::fake();

    $effect = (new OrderNotifications)->shippedLater(new Shopper(5));

    expect($effect)->not->toBe('');
    $push->assertQueued(PushMessage::class, 1);
    $push->assertNothingSent();
    $queued = $push->queued()->sole();
    expect($queued->queued)->toBeTrue()
        ->and($queued->queueOptions?->queue)->toBe('push')
        ->and($queued->result)->toBeNull();

    try {
        $push->assertNotQueued();
        $this->fail('should have failed');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('No push should have been queued')->toContain('[queued]');
    }
});

it('still refuses a raw string destination and a token without platform, exactly as in production', function (): void {
    MobilePush::fake();

    expect(fn () => MobilePush::to('a-raw-token')->send(PushMessage::make('x')))
        ->toThrow(Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException::class, 'needs its platform');
    expect(fn () => MobilePush::toToken(Platform::Android, '')->send(PushMessage::make('x')))->toThrow(Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException::class);
});

it('keeps the provider rule that APNs has no validate-only mode', function (): void {
    MobilePush::fake();

    expect(fn () => MobilePush::to(new Shopper(1))->validateOnly()->send(PushMessage::make('x')))
        ->toThrow(Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException::class);
});

it('is not the oracle of the wire: the same send reaches no transport and compiles nothing', function (): void {
    $push = MobilePush::fake();
    $transport = app(Transport::class);
    expect($transport)->toBeInstanceOf(SwitchableTransport::class);

    (new OrderNotifications)->shipped(new Shopper(7));

    $push->assertSentTimes(1);
    // The real pipeline was never asked for a request: the only thing between the manager and the provider was the
    // fake's placeholder transport, and it carried no payload.
    expect(app(Padosoft\MobilePush\Laravel\Testing\TestingSession::class)->wire())->toBeNull();
});

it('lets tests name their own connection', function (): void {
    $push = MobilePush::fake()
        ->connection('ios-eu', 'apns', 'com.example.eu')
        ->connection('ios-us', 'apns', 'com.example.us');

    MobilePush::connection('ios-eu')->to(new Shopper(1))->send(PushMessage::make('x'));
    MobilePush::connection('ios-us')->to(new Shopper(2))->send(PushMessage::make('x'));

    $push->assertSentOnConnection('ios-eu');
    $push->assertSentOnConnection('ios-us');
    expect($push->sent()->map(fn (SentPush $p): string => $p->binding->appId)->all())->toBe(['com.example.eu', 'com.example.us']);
});

it('puts everything back when the fake is reset', function (): void {
    $before = app()->environment();
    MobilePush::fake();
    expect(app()->environment())->toBe('production');

    $this->resetMobilePushFakes();

    expect(app()->environment())->toBe($before)
        ->and(app(Padosoft\MobilePush\Laravel\Testing\TestingSession::class)->active())->toBeFalse();
});
