<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\FakeNotAllowedException;
use Padosoft\MobilePush\Laravel\Testing\InteractsWithMobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;
use Padosoft\MobilePush\Laravel\Testing\SwitchableTransport;
use Padosoft\MobilePush\Laravel\Testing\TestingSession;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\OrderShipped;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\Shopper;

uses(InteractsWithMobilePush::class);

it('catches the push of my notification class', function (): void {
    $push = $this->fakeMobilePush();
    $user = new Shopper(7);

    $user->notify(new OrderShipped(42));

    $push->assertSentTo($user, fn (SentPush $p): bool => $p->message instanceof PushMessage
        && $p->message->title === 'Order shipped'
        && $p->message->data['order'] === '42');
    $push->assertSentTimes(1);
});

it('catches the push of my notification class on the wire too', function (): void {
    $wire = $this->fakeMobilePushTransport();

    (new Shopper(7))->notify(new OrderShipped(43));

    $wire->assertApnsRequest(fn (array $headers, array $payload): bool => $payload['aps']['alert']['body'] === 'Order #43 is on its way');
});

it('catches the helper functions too', function (): void {
    $push = $this->fakeMobilePush();

    mobile_push(new Shopper(7))->send(PushMessage::make('From the helper'));
    mobile_push_to_token(Platform::Android, 'a-token-from-a-test', 'Raw token helper');

    $push->assertSentTimes(2);
    $push->assertSent(fn (SentPush $p): bool => $p->message instanceof PushMessage && $p->message->title === 'Raw token helper');
});

it('works with an empty connections config, for both fakes', function (): void {
    config()->set('mobile-push.connections', []);

    $push = MobilePush::fake();
    MobilePush::to(new Shopper(1))->send(PushMessage::make('a'));
    $push->assertSentOnConnection('ios');

    $wire = MobilePush::fakeTransport();
    MobilePush::to(new Shopper(1))->send(PushMessage::make('a'));
    MobilePush::to(new Shopper(1, android: true))->send(PushMessage::make('a'));
    $wire->assertRequestCount(2);
});

it('uses the application\'s own connection names when it configures some', function (): void {
    config()->set('mobile-push.connections', [
        'shop-ios' => ['driver' => 'apns', 'bundle_id' => 'com.example.shop', 'team_id' => 'TEAM123456', 'environment' => 'production'],
        'shop-android' => ['driver' => 'fcm', 'project_id' => 'example-shop'],
    ]);
    $push = MobilePush::fake();

    MobilePush::to(new Shopper(1))->send(PushMessage::make('a'));
    MobilePush::to(new Shopper(2, android: true))->send(PushMessage::make('a'));

    $push->assertSentOnConnection('shop-ios');
    $push->assertSentOnConnection('shop-android');
    expect($push->sent()->map(fn (SentPush $p): string => $p->binding->appId)->all())->toBe(['com.example.shop', 'example-shop']);
});

it('refuses to be activated outside the testing environment', function (string $activate): void {
    app()->instance('env', 'production');

    expect(fn () => MobilePush::{$activate}())->toThrow(FakeNotAllowedException::class, 'not "testing"');
    expect(app(TestingSession::class)->active())->toBeFalse();
})->with(['fake', 'fakeTransport']);

it('can be allowed outside testing on purpose, and does not mistake its own environment switch for production', function (): void {
    app()->instance('env', 'staging');

    $push = MobilePush::fake(allowOutsideTesting: true);
    MobilePush::to(new Shopper(1))->send(PushMessage::make('a'));
    $again = MobilePush::fake(allowOutsideTesting: true);

    expect($push)->not->toBe($again);
    $again->assertNothingSent();
    app(TestingSession::class)->reset();
    expect(app()->environment())->toBe('staging');

    config()->set('mobile-push.testing.allow_fakes', true);
    expect(MobilePush::fakeTransport())->toBeObject();
});

it('swaps nothing until a fake is activated', function (): void {
    $transport = app(Transport::class);

    expect($transport)->toBeInstanceOf(SwitchableTransport::class)
        ->and($transport->active())->toBeFalse()
        ->and(app(TestingSession::class)->active())->toBeFalse()
        ->and(app()->environment())->toBe('testing');
});

it('does not let one fake leak into the next', function (): void {
    $first = MobilePush::fake();
    MobilePush::to(new Shopper(1))->send(PushMessage::make('a'));
    $first->assertSentTimes(1);

    $second = MobilePush::fakeTransport();

    expect(app(TestingSession::class)->intent())->toBeNull()
        ->and(app(Transport::class)->active())->toBeTrue();
    MobilePush::to(new Shopper(1))->send(PushMessage::make('b'));
    $second->assertRequestCount(1);
    $first->assertSentTimes(1); // the old fake recorded nothing more

    $third = MobilePush::fake();
    $third->assertNothingSent();
    MobilePush::to(new Shopper(1))->send(PushMessage::make('c'));
    $second->assertRequestCount(1); // and the wire fake saw nothing
    $third->assertSentTimes(1);

    $this->resetMobilePushFakes();
    expect(app(Transport::class)->active())->toBeFalse()
        ->and(app(TestingSession::class)->active())->toBeFalse()
        ->and(app()->environment())->toBe('testing');
});

it('starts every test clean (state lives in the application, which is new per test)', function (): void {
    expect(app(TestingSession::class)->active())->toBeFalse()
        ->and(app(TestingSession::class)->intent())->toBeNull();
});
