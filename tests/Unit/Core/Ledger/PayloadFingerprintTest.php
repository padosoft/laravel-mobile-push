<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Ledger\PayloadFingerprint;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;

it('is stable and versioned', function (): void {
    $a = PayloadFingerprint::of(PushMessage::make('Hi', 'there'));

    expect($a)->toMatch('/^v1:[0-9a-f]{64}$/')
        ->and(PayloadFingerprint::of(PushMessage::make('Hi', 'there')))->toBe($a);
});

it('ignores the order in which properties and map keys were set', function (): void {
    $a = PushMessage::make()->body('b')->title('t')->data(['x' => 1, 'y' => 'two'])->sound();
    $b = PushMessage::make()->sound()->data(['y' => 'two', 'x' => 1])->title('t')->body('b');

    expect(PayloadFingerprint::of($a))->toBe(PayloadFingerprint::of($b));
});

it('ignores the timezone a date was given in, not the instant', function (): void {
    $a = PushMessage::make('t')->expiresAt(new DateTimeImmutable('2026-11-20T12:00:00+01:00'));
    $b = PushMessage::make('t')->expiresAt(new DateTimeImmutable('2026-11-20T11:00:00Z'));
    $c = PushMessage::make('t')->expiresAt(new DateTimeImmutable('2026-11-20T11:00:00.001Z'));

    expect(PayloadFingerprint::of($a))->toBe(PayloadFingerprint::of($b))
        ->and(PayloadFingerprint::of($a))->not->toBe(PayloadFingerprint::of($c));
});

dataset('one change each', [
    'body' => [fn (PushMessage $m) => $m->body('Your order was cancelled')],
    'title' => [fn (PushMessage $m) => $m->title('Order 43')],
    'data value' => [fn (PushMessage $m) => $m->data(['order' => '43'])],
    'data value type' => [fn (PushMessage $m) => $m->data(['order' => 42])],
    'extra data key' => [fn (PushMessage $m) => $m->data(['coupon' => 'X'])],
    'badge' => [fn (PushMessage $m) => $m->badge(1)],
    'priority' => [fn (PushMessage $m) => $m->priority(MessagePriority::Normal)],
    'replaceable flag' => [fn (PushMessage $m) => $m->replaceable()],
    'ttl' => [fn (PushMessage $m) => $m->ttl(60)],
    'APNs overlay' => [fn (PushMessage $m) => $m->apns(static fn (ApnsMessage $apns) => $apns->threadId('orders'))],
]);

it('changes when the content changes', function (Closure $change): void {
    $base = fn (): PushMessage => PushMessage::make('Order 42', 'Your order shipped')->data(['order' => '42']);

    expect(PayloadFingerprint::of($change($base())))->not->toBe(PayloadFingerprint::of($base()));
})->with('one change each');

it('distinguishes 1 from 1.0 from "1", and a list order from another', function (): void {
    $of = static fn (array $data): string => PayloadFingerprint::of(PushMessage::make('t')->data($data));

    expect(array_unique([$of(['n' => 1]), $of(['n' => 1.0]), $of(['n' => '1'])]))->toHaveCount(3);

    $list = static fn (array $items): string => PayloadFingerprint::of(new class($items) implements Message
    {
        public function __construct(public array $items) {}
    });
    expect($list(['a', 'b']))->not->toBe($list(['b', 'a']))
        ->and($list(['k' => 'a', 'j' => 'b']))->toBe($list(['j' => 'b', 'k' => 'a']));
});

it('distinguishes two message classes with the same properties', function (): void {
    $one = new class implements Message
    {
        public string $text = 'x';
    };
    $two = new class implements Message
    {
        public string $text = 'x';
    };

    expect(PayloadFingerprint::of($one))->not->toBe(PayloadFingerprint::of($two));
});

it('refuses messages that are not plain data', function (): void {
    $withClosure = new class implements Message
    {
        public Closure $callback;

        public function __construct()
        {
            $this->callback = static fn () => null;
        }
    };

    expect(fn () => PayloadFingerprint::of($withClosure))->toThrow(InvalidMessageException::class);
});
