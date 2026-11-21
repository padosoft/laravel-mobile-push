<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Ledger\PayloadFingerprint;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

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

it('is keyed and bound to the scope: an OTP cannot be confirmed from the stored value without the key', function (): void {
    $key = str_repeat("\x01", 32);
    $cipher = new TokenCipher(new KeyRing([1 => $key]));
    $otp = static fn (string $code): PushMessage => PushMessage::make('Your code', $code);

    $stored = PayloadFingerprint::keyed($otp('481516'), $cipher, 'tenant-a');

    // Same message, same scope: same value; the version prefix tells it apart from the legacy form.
    expect($stored)->toMatch('/^v2:k1:[0-9a-f]{64}$/')
        ->and(PayloadFingerprint::keyed($otp('481516'), $cipher, 'tenant-a'))->toBe($stored)
        ->and(PayloadFingerprint::matches($stored, $otp('481516'), $cipher, 'tenant-a'))->toBeTrue()
        ->and(PayloadFingerprint::matches($stored, $otp('481517'), $cipher, 'tenant-a'))->toBeFalse()
        // Another scope, or another key, gives an unrelated value.
        ->and(PayloadFingerprint::keyed($otp('481516'), $cipher, 'tenant-b'))->not->toBe($stored)
        ->and(PayloadFingerprint::keyed($otp('481516'), new TokenCipher(new KeyRing([1 => str_repeat("\x02", 32)])), 'tenant-a'))->not->toBe($stored);

    // An attacker holding the database but not the key enumerates all six-digit codes with the unkeyed hash: none matches.
    $hex = substr($stored, strlen('v2:k1:'));
    $found = false;
    for ($i = 481500; $i < 481530; $i++) {
        $guess = PayloadFingerprint::of($otp(sprintf('%06d', $i)));
        $found = $found || str_ends_with($guess, $hex);
    }
    expect($found)->toBeFalse();
});

it('still verifies legacy unkeyed values and keeps verifying after a key rotation', function (): void {
    $old = new TokenCipher(new KeyRing([1 => str_repeat('a', 32)]));
    $rotated = new TokenCipher(new KeyRing([1 => str_repeat('a', 32), 2 => str_repeat('b', 32)]));
    $dropped = new TokenCipher(new KeyRing([2 => str_repeat('b', 32)]));
    $message = PushMessage::make('Hi');
    $storedWithV1 = PayloadFingerprint::keyed($message, $old, 'default');

    expect(PayloadFingerprint::matches(PayloadFingerprint::of($message), $message, $rotated, 'default'))->toBeTrue()
        ->and(PayloadFingerprint::matches($storedWithV1, $message, $rotated, 'default'))->toBeTrue()
        ->and(PayloadFingerprint::keyed($message, $rotated, 'default'))->toStartWith('v2:k2:')
        ->and(PayloadFingerprint::matches($storedWithV1, $message, $dropped, 'default'))->toBeFalse()
        ->and(PayloadFingerprint::matches('garbage', $message, $rotated, 'default'))->toBeFalse();
});

enum FpSuit
{
    case Hearts;
    case Spades;
}

class FpBase
{
    public string $shared = 'base';

    protected int $prot = 2;

    private string $hidden = 'h';
}

final class FpSample extends FpBase implements Message
{
    public static int $counter = 5;

    public string $text = 'a/b é';

    public float $ratio = 0.1;

    /** @var array<mixed> */
    public array $map = ['b' => 1, 'a' => [1, 'two']];

    /** @var array<mixed> */
    public array $nums = [2 => 'x', 1 => 'y'];

    public DateTimeImmutable $at;

    public FpSuit $suit = FpSuit::Hearts;

    public string $late;

    public ?string $none = null;

    public bool $flag = true;

    public int $n = 7;

    private int $own = 3;

    public function __construct()
    {
        $this->at = new DateTimeImmutable('2026-11-20T12:00:00+01:00');
    }

    public function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;

        return $copy;
    }
}

const FP_GOLDEN_JSON = '{"@class":"FpSample","@props":{"FpBase::hidden":"h","at":{"@time":"2026-11-20T11:00:00.000000"},'
    .'"flag":true,"late":{"@uninitialized":true},"map":{"@map":[["a",[1,"two"]],["b",1]]},"n":7,"none":null,'
    .'"nums":{"@map":[["1","y"],["2","x"]]},"own":3,"prot":2,"ratio":{"@float":"0.10000000000000001"},"shared":"base",'
    .'"suit":{"@enum":"FpSuit","case":"Hearts"},"text":"a/b é"}}';

it('fingerprints the exact canonical JSON: sorted props, tagged float/enum/date/map, parent privates, no statics', function (): void {
    $sample = new FpSample;

    expect(PayloadFingerprint::of($sample))->toBe('v1:'.hash('sha256', FP_GOLDEN_JSON));
});

it('stores an unescaped, URL-safe-slash canonical JSON (the legacy hash must not drift)', function (): void {
    $slashy = (new FpSample)->with('text', 'https://x.test/é');
    $json = str_replace('"a/b é"', '"https://x.test/é"', FP_GOLDEN_JSON);

    expect(PayloadFingerprint::of($slashy))->toBe('v1:'.hash('sha256', $json));
});

it('changes when any single canonical field changes', function (string $property, mixed $value): void {
    $base = new FpSample;

    expect(PayloadFingerprint::of($base->with($property, $value)))->not->toBe(PayloadFingerprint::of($base));
})->with([
    'string' => ['text', 'other'],
    'float' => ['ratio', 0.2],
    'map value' => ['map', ['b' => 2, 'a' => [1, 'two']]],
    'map key' => ['map', ['c' => 1, 'a' => [1, 'two']]],
    'numeric map key' => ['nums', [3 => 'x', 1 => 'y']],
    'list item' => ['map', ['b' => 1, 'a' => [1, 'three']]],
    'date instant' => ['at', new DateTimeImmutable('2026-11-20T11:00:01Z')],
    'enum case' => ['suit', FpSuit::Spades],
    'bool' => ['flag', false],
    'int' => ['n', 8],
    'null to value' => ['none', ''],
    'initialising the uninitialized property' => ['late', 'now'],
    'own private' => ['own', 4],
    'protected' => ['prot', 3],
    'inherited public' => ['shared', 'changed'],
]);

it('ignores static properties', function (): void {
    $before = PayloadFingerprint::of(new FpSample);
    FpSample::$counter = 99;

    try {
        expect(PayloadFingerprint::of(new FpSample))->toBe($before);
    } finally {
        FpSample::$counter = 5;
    }
});

it('refuses a string that is not valid UTF-8 instead of hashing nothing', function (): void {
    expect(fn () => PayloadFingerprint::of((new FpSample)->with('text', "bad \xB1\x31")))
        ->toThrow(InvalidMessageException::class, 'UTF-8');
});

it('does not take a value with a version prefix but no colon for a stored fingerprint', function (): void {
    $cipher = new TokenCipher(new KeyRing([1 => str_repeat("\x01", 32)]));
    $message = PushMessage::make('Hi');
    $stored = PayloadFingerprint::keyed($message, $cipher, 'default');
    $forged = 'v2Z'.substr($stored, strlen('v2:'));

    expect(PayloadFingerprint::matches($stored, $message, $cipher, 'default'))->toBeTrue()
        ->and(PayloadFingerprint::matches($forged, $message, $cipher, 'default'))->toBeFalse();
});

it('compares fingerprints for equality', function (): void {
    expect(PayloadFingerprint::equals('v1:ab', 'v1:ab'))->toBeTrue()
        ->and(PayloadFingerprint::equals('v1:ab', 'v1:ac'))->toBeFalse();
});
