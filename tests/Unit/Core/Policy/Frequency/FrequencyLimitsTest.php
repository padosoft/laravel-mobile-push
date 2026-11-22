<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Policy\Frequency\BucketLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyDecision;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyWindow;

it('parses the limit table with a default, per-bucket limits and unlimited', function () {
    $limits = FrequencyLimits::fromArray([
        'default' => ['per_hour' => 5, 'per_day' => '20'],
        'marketing' => ['per_day' => 2],
        'security' => 'unlimited',
    ]);

    $default = $limits->forBucket('anything-else');
    expect(array_map(fn ($w) => $w->describe(), $default->windows))->toBe(['5 per hour', '20 per day'])
        ->and(array_map(fn ($w) => $w->describe(), $limits->forBucket('marketing')->windows))->toBe(['2 per day'])
        ->and($limits->forBucket('security')->isUnlimited())->toBeTrue()
        ->and($default->longestWindowSeconds())->toBe(86400)
        ->and($default->largestLimit())->toBe(20);
});

it('treats every bucket as unlimited when the table has no default', function () {
    expect(FrequencyLimits::fromArray(['marketing' => ['per_day' => 2]])->forBucket('orders')->isUnlimited())->toBeTrue()
        ->and((new FrequencyLimits)->forBucket('x')->isUnlimited())->toBeTrue();
});

it('rejects entries that would silently remove or garble a cap', function (mixed $entry) {
    FrequencyLimits::fromArray(['marketing' => $entry]);
})->with([
    'empty map' => [[]],
    'zero' => [['per_day' => 0]],
    'negative' => [['per_hour' => -1]],
    'float' => [['per_day' => 1.5]],
    'text' => [['per_day' => 'many']],
    'typo' => [['per_days' => 3]],
    'scalar' => [3],
])->throws(ConfigurationException::class);

it('puts a moment inside the window only when it is strictly newer than now minus the window', function (int $age, bool $inside) {
    $window = new FrequencyWindow('hour', 3600, 1);

    expect($window->contains(10_000 - $age, 10_000))->toBe($inside);
})->with([
    'just now' => [0, true],
    'one second short of an hour' => [3599, true],
    'exactly an hour' => [3600, false],
    'older' => [3601, false],
]);

it('rejects a bucket without a name', function () {
    FrequencyLimits::fromArray(['' => ['per_day' => 1]]);
})->throws(ConfigurationException::class);

it('accepts a bucket whose name is a number (PHP turns the key into an int)', function () {
    $limits = FrequencyLimits::fromArray(['7' => ['per_day' => 1]]);

    expect(array_map(fn ($w) => $w->describe(), $limits->forBucket('7')->windows))->toBe(['1 per day'])
        ->and($limits->forBucket('8')->isUnlimited())->toBeTrue();
});

it('needs positive seconds and limit for a window, one included', function (int $seconds, int $limit, bool $valid) {
    $make = fn () => new FrequencyWindow('hour', $seconds, $limit);

    $valid ? expect($make()->describe())->toBe($limit.' per hour') : expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'one second, one effect' => [1, 1, true],
    'zero seconds' => [0, 1, false],
    'zero effects' => [1, 0, false],
    'negative seconds' => [-1, 5, false],
]);

it('describes an unlimited bucket as having no window and no limit', function () {
    $unlimited = BucketLimits::unlimited();

    expect($unlimited->isUnlimited())->toBeTrue()
        ->and($unlimited->longestWindowSeconds())->toBe(0)
        ->and($unlimited->largestLimit())->toBe(0)
        ->and($unlimited->windowNamed('day'))->toBeNull();
});

it('finds a window by name', function () {
    $limits = BucketLimits::of(perHour: 5, perDay: 20);

    expect($limits->windowNamed('hour')?->limit)->toBe(5)
        ->and($limits->windowNamed('day')?->seconds)->toBe(86400)
        ->and($limits->windowNamed('week'))->toBeNull()
        ->and(BucketLimits::of(perHour: 2)->longestWindowSeconds())->toBe(3600)
        ->and(BucketLimits::of(perHour: 2)->largestLimit())->toBe(2);
});

it('round-trips a capped decision with its window and limit', function (string $window, int $limit) {
    $decoded = FrequencyDecision::decode(FrequencyDecision::capped($window, $limit)->encode());

    expect($decoded?->allowed)->toBeFalse()
        ->and($decoded?->window)->toBe($window)
        ->and($decoded?->limit)->toBe($limit)
        ->and(FrequencyDecision::allowed()->describeLimit())->toBeNull();
})->with([
    ['hour', 5],
    ['day', 120],
]);
