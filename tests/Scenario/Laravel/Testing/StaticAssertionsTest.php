<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\InteractsWithMobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\Shopper;
use PHPUnit\Framework\ExpectationFailedException;

uses(InteractsWithMobilePush::class);

function sendOrderPush(): void
{
    MobilePush::to(new Shopper(1))->send(PushMessage::make('Order shipped'));
}

it('forwards each assertion to the active fake: it passes when true and fails when false', function (string $name, array $passing, array $failing, ?bool $queue): void {
    MobilePush::fake();
    $queue === true ? MobilePush::to(new Shopper(1))->queue(PushMessage::make('Order shipped')) : sendOrderPush();

    MobilePush::{$name}(...$passing);

    expect(fn () => MobilePush::{$name}(...$failing))->toThrow(ExpectationFailedException::class);
})->with([
    'assertSent' => ['assertSent', [PushMessage::class, 1], [PushMessage::class, 5], false],
    'assertNotSent' => ['assertNotSent', [fn (SentPush $p): bool => false], [PushMessage::class], false],
    'assertSentTimes' => ['assertSentTimes', [1], [3], false],
    'assertSentTo' => ['assertSentTo', [new Shopper(1)], [new Shopper(1), fn (): bool => false], false],
    'assertSentOnConnection' => ['assertSentOnConnection', ['ios'], ['nope'], false],
    'assertSentInScope' => ['assertSentInScope', ['default'], ['other'], false],
    'assertQueued' => ['assertQueued', [PushMessage::class, 1], [PushMessage::class, 4], true],
    'assertNotQueued' => ['assertNotQueued', [fn (SentPush $p): bool => false], [], true],
])->skip(fn () => false);

it('forwards assertNothingSent', function (): void {
    MobilePush::fake();
    MobilePush::assertNothingSent();

    sendOrderPush();

    expect(fn () => MobilePush::assertNothingSent())->toThrow(ExpectationFailedException::class);
});

it('forwards assertSentToTopic', function (): void {
    MobilePush::fake();
    MobilePush::toTopic('sale')->send(PushMessage::make('Sale'));

    MobilePush::assertSentToTopic('sale');
    expect(fn () => MobilePush::assertSentToTopic('other'))->toThrow(ExpectationFailedException::class);
});

it('answers sent() and queued() from the active fake', function (): void {
    MobilePush::fake();
    sendOrderPush();
    MobilePush::to(new Shopper(2))->queue(PushMessage::make('Later'));

    expect(MobilePush::sent())->toHaveCount(1)
        ->and(MobilePush::sent()->sole()->message)->toBeInstanceOf(PushMessage::class)
        ->and(MobilePush::queued())->toHaveCount(1)
        ->and(MobilePush::queued(fn (SentPush $p): bool => false))->toHaveCount(0);
});

it('mixes the static style with the object fake() returned', function (): void {
    $push = MobilePush::fake();
    sendOrderPush();

    MobilePush::assertSentTimes(1);
    $push->assertSentTimes(1);
    sendOrderPush();
    $push->assertSentTimes(2);
    MobilePush::assertSentTimes(2);
    expect(MobilePush::sent()->all())->toEqual($push->sent()->all());
});

it('refuses a static assertion when no fake is active, with a message that says what to do', function (string $name, array $args): void {
    expect(fn () => MobilePush::{$name}(...$args))
        ->toThrow(LogicException::class, "MobilePush::{$name}() needs the intent fake, and none is active: call MobilePush::fake() first");
})->with([
    ['assertSent', [PushMessage::class]], ['assertNotSent', [PushMessage::class]], ['assertNothingSent', []],
    ['assertSentTimes', [0]], ['assertSentTo', [new Shopper(1)]], ['assertSentOnConnection', ['ios']],
    ['assertSentInScope', ['default']], ['assertSentToTopic', ['t']], ['assertQueued', []], ['assertNotQueued', []],
    ['sent', []], ['queued', []],
]);

it('does not treat the wire fake as an intent fake', function (): void {
    MobilePush::fakeTransport();

    expect(fn () => MobilePush::assertNothingSent())->toThrow(LogicException::class, 'call MobilePush::fake() first');
});

it('does not keep a switched-off fake: the static calls stop working after reset', function (): void {
    $push = MobilePush::fake();
    sendOrderPush();
    MobilePush::assertSentTimes(1);

    $this->resetMobilePushFakes();

    expect(fn () => MobilePush::assertSentTimes(1))->toThrow(LogicException::class, 'call MobilePush::fake() first');
    $push->assertSentTimes(1);
});

it('starts the next test without a fake (state lives in the per-test application)', function (): void {
    expect(fn () => MobilePush::assertNothingSent())->toThrow(LogicException::class);
});

it('still forwards every other static call to the manager', function (): void {
    expect(MobilePush::currentScope())->toBe('default')
        ->and(fn () => MobilePush::noSuchMethod())->toThrow(Error::class, 'noSuchMethod');
});
