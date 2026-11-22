<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyLimits;

/*
 * docs/policies.md: "Values must be positive integers (numeric strings from env() are accepted); an empty map, a zero
 * or negative number, or an unknown key is a ConfigurationException, so a typo cannot silently remove a cap."
 * A null used to slip through: `'per_day' => env('SOME_UNSET_VARIABLE')` was read as "no window" and the bucket
 * became unlimited without a word. Found by mutation testing on 2026-11-22; a present key must now hold a number,
 * and a bucket must be a map or the string `unlimited`.
 */
it('rejects a limit map whose values are all null instead of silently removing the cap', function (array $entry) {
    $limits = FrequencyLimits::fromArray(['marketing' => $entry]);

    expect($limits->forBucket('marketing')->isUnlimited())->toBeFalse('the bucket became unlimited');
})->with([
    'per_day null' => [['per_day' => null]],
    'both null' => [['per_hour' => null, 'per_day' => null]],
    'one window null next to a real one' => [['per_hour' => null, 'per_day' => 20]],
])->throws(ConfigurationException::class);

it('rejects a bucket that is null instead of treating it as unlimited', function () {
    FrequencyLimits::fromArray(['marketing' => null]);
})->throws(ConfigurationException::class);

it('still reads an absent key as "no such window" and the word unlimited as no cap', function () {
    $limits = FrequencyLimits::fromArray(['marketing' => ['per_day' => '2'], 'security' => 'unlimited']);

    expect($limits->forBucket('marketing')->isUnlimited())->toBeFalse()
        ->and($limits->forBucket('marketing')->longestWindowSeconds())->toBe(86400)
        ->and($limits->forBucket('security')->isUnlimited())->toBeTrue();
});
