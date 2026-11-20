<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Logging\DatabaseMode;
use Padosoft\MobilePush\Laravel\Logging\DatabaseSink;
use Padosoft\MobilePush\Laravel\Logging\LogRecord;
use Padosoft\MobilePush\Laravel\Logging\LogRecordFactory;
use Padosoft\MobilePush\Laravel\Logging\LogSink;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Support\FakeClock;

it('samples with exact boundaries', function (float $rate, int $draw, bool $keep) {
    expect(DatabaseSink::sample($rate, $draw))->toBe($keep);
})->with([
    'rate 0 keeps nothing' => [0.0, 0, false],
    'rate 1 keeps everything' => [1.0, 999_999, true],
    '1% keeps draw 9999' => [0.01, 9_999, true],
    '1% drops draw 10000' => [0.01, 10_000, false],
    'rate above 1 is clamped' => [7.0, 999_999, true],
    'negative rate is clamped' => [-1.0, 0, false],
]);

it('applies the mode: failures always, routine successes by draw', function () {
    $make = fn (SendStatus $s) => (new LogRecordFactory(new Redactor, new TokenIndex(new KeyRing([1 => str_repeat('k', 32)])), new FakeClock))
        ->make(F::binding(), F::result(F::token(1), $s));
    $db = Mockery::mock(ConnectionResolverInterface::class);
    $draw = 0;
    $sink = function (DatabaseMode $m) use ($db, &$draw): DatabaseSink {
        return new DatabaseSink($db, mode: $m, sampleRate: 0.01, random: function () use (&$draw): int {
            return $draw;
        });
    };

    $draw = 500_000; // a losing draw at 1%
    expect($sink(DatabaseMode::All)->accepts($make(SendStatus::Accepted)))->toBeTrue()
        ->and($sink(DatabaseMode::Failures)->accepts($make(SendStatus::Accepted)))->toBeFalse()
        ->and($sink(DatabaseMode::Failures)->accepts($make(SendStatus::Simulated)))->toBeFalse()
        ->and($sink(DatabaseMode::Failures)->accepts($make(SendStatus::OutcomeUnknown)))->toBeTrue()
        ->and($sink(DatabaseMode::Sampled)->accepts($make(SendStatus::Accepted)))->toBeFalse()
        ->and($sink(DatabaseMode::Sampled)->accepts($make(SendStatus::Rejected)))->toBeTrue();

    $draw = 0;
    expect($sink(DatabaseMode::Sampled)->accepts($make(SendStatus::Accepted)))->toBeTrue();
});

it('drops the oldest record beyond the hard cap and counts it', function () {
    $factory = new LogRecordFactory(new Redactor, new TokenIndex(new KeyRing([1 => str_repeat('k', 32)])), new FakeClock);
    $seen = [];
    $sink = new class($seen) implements LogSink
    {
        public function __construct(private array &$seen) {}

        public function write(LogRecord ...$records): void
        {
            foreach ($records as $r) {
                $this->seen[] = $r->deliveryId;
            }
        }

        public function flush(): void {}
    };
    $manager = new SinkManager(['spy' => $sink], flushSize: 0, maxBuffer: 5, factory: $factory);

    for ($i = 1; $i <= 8; $i++) {
        $manager->record($factory->make(F::binding(), F::result(F::token($i))));
    }
    expect($manager->buffered())->toBe(5)
        ->and($manager->stats()['sinks']['spy']['dropped'])->toBe(3)
        ->and($manager->stats()['peak_buffered'])->toBe(5);

    $manager->flush();

    expect($seen)->toBe(array_map(fn (int $i): string => F::result(F::token($i))->deliveryId, range(4, 8)))
        ->and($manager->stats()['sinks']['spy']['written'])->toBe(5)
        ->and($manager->buffered())->toBe(0);
});
