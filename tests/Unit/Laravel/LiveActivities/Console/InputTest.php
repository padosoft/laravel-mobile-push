<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;

const NOW = '2026-11-21T10:00:00+00:00';

it('accepts only a JSON object', function (): void {
    expect(Input::jsonObject('state', '{"eta":5,"nested":{"a":[1]}}'))->toBe(['eta' => 5, 'nested' => ['a' => [1]]])
        ->and(Input::jsonObject('state', '{}'))->toBe([])
        ->and(Input::jsonObject('state', null))->toBeNull()
        ->and(Input::jsonObject('state', '  '))->toBeNull();
});

it('rejects arrays, scalars and broken JSON with the option name', function (string $raw, string $message): void {
    expect(fn () => Input::jsonObject('state', $raw))->toThrow(InvalidInputException::class, $message);
})->with([
    'list' => ['[1,2]', '--state must be a JSON object'],
    'empty list' => ['[]', 'JSON list'],
    'string' => ['"x"', 'JSON string'],
    'number' => ['5', 'JSON int'],
    'null' => ['null', 'JSON null'],
    'syntax' => ['{eta:1}', '--state is not valid JSON'],
]);

it('parses ISO-8601 instants and signed relative durations', function (string $value, string $expected): void {
    $now = new DateTimeImmutable(NOW);

    expect(Input::instant('stale', $value, $now)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))->toBe($expected);
})->with([
    'zulu' => ['2026-11-21T10:30:00Z', '2026-11-21T10:30:00Z'],
    'offset' => ['2026-11-21T12:30:00+02:00', '2026-11-21T10:30:00Z'],
    'plus minutes' => ['+10 minutes', '2026-11-21T10:10:00Z'],
    'plus compact' => ['+90s', '2026-11-21T10:01:30Z'],
    'singular hour' => ['+1 hour', '2026-11-21T11:00:00Z'],
    'minus day' => ['-1 day', '2026-11-20T10:00:00Z'],
    'weeks' => ['+2 weeks', '2026-12-05T10:00:00Z'],
]);

it('rejects ambiguous instants', function (string $value): void {
    expect(fn () => Input::instant('dismiss', $value, new DateTimeImmutable(NOW)))->toThrow(InvalidInputException::class, '--dismiss');
})->with(['no sign' => '10 minutes', 'no offset' => '2026-11-21T10:30:00', 'word' => 'tomorrow', 'unit' => '+5 fortnights', 'empty' => '']);

it('splits an owner at the last colon', function (): void {
    expect(Input::owner('App\\Models\\User:42'))->toBe(['App\\Models\\User', '42'])
        ->and(Input::owner('user:a:b'))->toBe(['user:a', 'b']);
    foreach (['User', ':1', 'User:'] as $bad) {
        expect(fn () => Input::owner($bad))->toThrow(InvalidInputException::class);
    }
});
