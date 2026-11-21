<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Retry\Backoff;
use Padosoft\MobilePush\Core\Retry\RetryAfter;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('never schedules before the provider minimum and stays within its bounds (property)', function (): void {
    $random = new Randomizer(new Mt19937(20261120));

    for ($i = 0; $i < 2000; $i++) {
        $policy = new RetryPolicy(
            backoffBaseMs: $base = $random->getInt(0, 5000),
            backoffMaxMs: $max = $base + $random->getInt(0, 600_000),
            jitter: $jitter = $random->getFloat(0.0, 1.0),
        );
        $backoff = new Backoff($policy, new Randomizer(new Mt19937($i)));
        $retry = $random->getInt(1, 80);
        $min = $random->getInt(0, 3) === 0 ? null : $random->getInt(0, 1200);

        $delay = $backoff->delayMs($retry, $min);
        $floor = ($min ?? 0) * 1000;
        $upper = (int) ceil(max($max, $floor * (1 + $jitter)));

        expect($delay)->toBeGreaterThanOrEqual($floor)->toBeLessThanOrEqual($upper);
        $exp = min($max, $base * 2 ** min($retry - 1, 62));
        if ($floor < $exp) {
            // Above the provider minimum the jitter only spreads downwards: never later than the exponential step.
            expect($delay)->toBeLessThanOrEqual((int) ceil($exp));
        }
    }
});

it('grows exponentially without jitter and caps at the maximum', function (): void {
    $backoff = new Backoff(new RetryPolicy(backoffBaseMs: 1000, backoffMaxMs: 10_000, jitter: 0.0));

    expect(array_map(static fn (int $n): int => $backoff->delayMs($n), [1, 2, 3, 4, 5, 6, 200]))
        ->toBe([1000, 2000, 4000, 8000, 10_000, 10_000, 10_000]);
});

it('spreads retries above a provider minimum instead of stacking them on it', function (): void {
    $backoff = new Backoff(new RetryPolicy(backoffBaseMs: 1000, jitter: 0.5), new Randomizer(new Mt19937(1)));

    $delays = array_map(static fn (): int => $backoff->delayMs(1, 60), range(1, 200));

    expect(min($delays))->toBeGreaterThanOrEqual(60_000)
        ->and(max($delays))->toBeLessThanOrEqual(90_000)
        ->and(count(array_unique($delays)))->toBeGreaterThan(100);
});

it('computes the deadline as min(logical expiry, first attempt + maxElapsed)', function (?string $expiry, string $deadline, bool $isExpiry): void {
    $budget = RetryBudget::start(new RetryPolicy(maxElapsedSeconds: 3600), new DateTimeImmutable('2026-11-20T10:00:00Z'), $expiry === null ? null : new DateTimeImmutable($expiry));

    expect($budget->deadline()->format(DATE_ATOM))->toBe($deadline)
        ->and($budget->deadlineIsExpiry())->toBe($isExpiry);
})->with([
    'no expiry' => [null, '2026-11-20T11:00:00+00:00', false],
    'expiry first' => ['2026-11-20T10:10:00Z', '2026-11-20T10:10:00+00:00', true],
    'elapsed first' => ['2026-11-21T10:00:00Z', '2026-11-20T11:00:00+00:00', false],
    'expiry in another zone' => ['2026-11-20T11:10:00+01:00', '2026-11-20T10:10:00+00:00', true],
]);

it('knows when the logical expiry has passed', function (): void {
    $budget = RetryBudget::start(new RetryPolicy, new DateTimeImmutable('2026-11-20T10:00:00Z'), new DateTimeImmutable('2026-11-20T10:10:00Z'));

    expect($budget->isExpired(new DateTimeImmutable('2026-11-20T10:09:59Z')))->toBeFalse()
        ->and($budget->isExpired(new DateTimeImmutable('2026-11-20T10:10:00Z')))->toBeTrue()
        ->and(RetryBudget::start(new RetryPolicy, new DateTimeImmutable)->isExpired(new DateTimeImmutable('+10 years')))->toBeFalse();
});

it('round-trips through an array exactly, microseconds included (property)', function (): void {
    $random = new Randomizer(new Mt19937(7));

    for ($i = 0; $i < 300; $i++) {
        $start = (new DateTimeImmutable('@'.$random->getInt(1_600_000_000, 1_900_000_000)))->modify(sprintf('+%d usec', $random->getInt(0, 999_999)));
        $expiry = $random->getInt(0, 1) === 1 ? $start->modify(sprintf('+%d seconds', $random->getInt(0, 90_000))) : null;
        $budget = RetryBudget::start(new RetryPolicy(maxElapsedSeconds: $random->getInt(0, 86_400)), $start, $expiry);
        for ($n = $random->getInt(0, 6); $n > 0; $n--) {
            $budget = $budget->withAttempt();
        }
        if ($random->getInt(0, 1) === 1) {
            $budget = $budget->withUnknownOutcome()->withTokenRefresh();
        }

        $array = $budget->toArray();
        $copy = RetryBudget::fromArray(json_decode((string) json_encode($array), true));

        expect($copy->toArray())->toBe($array)
            ->and($copy->deadline() == $budget->deadline())->toBeTrue()
            ->and($copy->startedAt == $start)->toBeTrue();
    }
});

it('refuses a corrupted stored budget', function (array $data): void {
    RetryBudget::fromArray($data);
})->with([
    'empty' => [[]],
    'wrong version' => [['v' => 2]],
    'negative attempts' => [['v' => 1, 'attempts' => -1, 'token_refreshes' => 0, 'unknown_outcome_seen' => false, 'started_at' => '2026-11-20T10:00:00.000000+00:00', 'elapsed_deadline' => '2026-11-20T10:00:00.000000+00:00']],
    'bad date' => [['v' => 1, 'attempts' => 1, 'token_refreshes' => 0, 'unknown_outcome_seen' => false, 'started_at' => 'yesterday', 'elapsed_deadline' => '2026-11-20T10:00:00.000000+00:00']],
])->throws(InvalidArgumentException::class);

it('rejects an out-of-range policy before the network', function (array $args): void {
    new RetryPolicy(...$args);
})->with([
    [['maxAttempts' => 0]],
    [['maxElapsedSeconds' => -1]],
    [['backoffBaseMs' => 10, 'backoffMaxMs' => 5]],
    [['jitter' => 1.5]],
    [['inlineThresholdMs' => -1]],
])->throws(ConfigurationException::class);

it('parses Retry-After in both forms', function (?string $value, ?int $expected): void {
    expect(RetryAfter::seconds($value, new DateTimeImmutable('2026-11-20T10:00:00Z')))->toBe($expected);
})->with([
    [null, null], ['', null], ['0', 0], [' 45 ', 45], ['99999999999', RetryAfter::MAX_SECONDS],
    ['Fri, 20 Nov 2026 10:01:00 GMT', 60], ['Fri, 20 Nov 2026 09:00:00 GMT', 0],
    ['-5', null], ['1.5', null], ['Fri, 32 Nov 2026 10:01:00 GMT', null], ['tomorrow', null],
]);

it('carries the single-attempt flag through serialization and defaults to false for older stored budgets', function () {
    $now = new DateTimeImmutable('2026-11-20T10:00:00Z');
    $budget = RetryBudget::start(new RetryPolicy, $now, $now, true);
    $stored = $budget->toArray();

    expect($budget->isExpired($now))->toBeFalse()                      // its one attempt is still allowed
        ->and($budget->withAttempt()->isExpired($now))->toBeTrue()     // and nothing after it
        ->and(RetryBudget::fromArray($stored)->singleAttempt)->toBeTrue();

    unset($stored['single_attempt']);
    expect(RetryBudget::fromArray($stored)->singleAttempt)->toBeFalse();
});

it('clamps Retry-After to one day (86400 s) exactly, and reads leading zeros as delta-seconds', function (string $value, int $expected): void {
    expect(RetryAfter::seconds($value, new DateTimeImmutable('2026-11-20T10:00:00Z')))->toBe($expected);
})->with([
    'just below the cap' => ['86399', 86_399],
    'at the cap' => ['86400', 86_400],
    'just above the cap' => ['86401', 86_400],
    'six digits' => ['999999', 86_400],
    'six characters with leading zeros' => ['000005', 5],
    'HTTP-date two days ahead' => ['Sun, 22 Nov 2026 10:00:00 GMT', 86_400],
]);

it('accepts the three HTTP-date forms recipients must read, surrounding spaces included', function (string $value): void {
    expect(RetryAfter::httpDate($value)?->format(DATE_ATOM))->toBe('2026-11-20T10:02:00+00:00');
})->with([
    'IMF-fixdate' => ['Fri, 20 Nov 2026 10:02:00 GMT'],
    'RFC 850' => ['Friday, 20-Nov-26 10:02:00 GMT'],
    'asctime' => ['Fri Nov 20 10:02:00 2026'],
    'asctime with a padded day' => ['Fri Nov  20 10:02:00 2026'],
    'surrounded by spaces' => ['  Fri, 20 Nov 2026 10:02:00 GMT  '],
]);

it('treats retry numbers below 1 as the first retry', function (): void {
    $backoff = new Backoff(new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0));

    expect($backoff->delayMs(0))->toBe(1000)->and($backoff->delayMs(-3))->toBe(1000);
});

it('caps the exponent at 2^62 so an extreme policy never overflows into a negative delay', function (): void {
    $backoff = new Backoff(new RetryPolicy(backoffBaseMs: 1, backoffMaxMs: PHP_INT_MAX, jitter: 0.0));

    expect($backoff->delayMs(63))->toBe(2 ** 62)
        ->and($backoff->delayMs(500))->toBe(2 ** 62);
});

it('spreads UP from the provider minimum when it equals the exponential step (boundary minimum == exp)', function (): void {
    $backoff = new Backoff(new RetryPolicy(backoffBaseMs: 1000, jitter: 0.5), new Randomizer(new Mt19937(3)));

    $delays = array_map(static fn (): int => $backoff->delayMs(1, 1), range(1, 50));

    expect(min($delays))->toBeGreaterThanOrEqual(1000)
        ->and(max($delays))->toBeGreaterThan(1000)->toBeLessThanOrEqual(1500);
});

it('rounds a jittered delay up to the next millisecond', function (): void {
    // exp * (1 - spread) lies in [999, 1000]: rounding up always lands on 1000.
    $backoff = new Backoff(new RetryPolicy(backoffBaseMs: 1000, jitter: 0.001), new Randomizer(new Mt19937(11)));

    expect(array_unique(array_map(static fn (): int => $backoff->delayMs(1), range(1, 100))))->toBe([1000]);
});

it('stores budgets as version 1', function (): void {
    $stored = RetryBudget::start(new RetryPolicy, new DateTimeImmutable('2026-11-20T10:00:00Z'))->toArray();

    expect($stored['v'])->toBe(1)
        ->and(RetryBudget::fromArray(['v' => 1] + $stored)->attempts)->toBe(0);
});

it('makes the logical expiry the binding deadline when it coincides with first attempt + maxElapsed', function (): void {
    $budget = RetryBudget::start(new RetryPolicy(maxElapsedSeconds: 600), new DateTimeImmutable('2026-11-20T10:00:00Z'), new DateTimeImmutable('2026-11-20T10:10:00Z'));

    expect($budget->deadlineIsExpiry())->toBeTrue()
        ->and($budget->deadline()->format(DATE_ATOM))->toBe('2026-11-20T10:10:00+00:00');
});

it('refuses a stored budget with a negative token-refresh count or a non-string timestamp', function (array $override): void {
    $valid = RetryBudget::start(new RetryPolicy, new DateTimeImmutable('2026-11-20T10:00:00Z'))->toArray();

    RetryBudget::fromArray($override + $valid);
})->with([
    'token_refreshes = -1' => [['token_refreshes' => -1]],
    'started_at is a number' => [['started_at' => 1_763_632_800]],
    'elapsed_deadline is null' => [['elapsed_deadline' => null]],
])->throws(InvalidArgumentException::class);
