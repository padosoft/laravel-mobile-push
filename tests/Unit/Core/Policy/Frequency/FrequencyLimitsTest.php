<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
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
