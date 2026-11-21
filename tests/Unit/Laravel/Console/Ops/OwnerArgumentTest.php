<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\ErasureReport;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerRef;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\StoreReport;

/*
 * Dense pieces only: the owner argument grammar and how a forget report reads. The behaviour is in the scenarios.
 */

it('parses Type:id at the last colon and tidies shell-escaped class names', function (string $argument, string $type, string $id): void {
    $owner = OwnerRef::parse($argument);

    expect($owner->type)->toBe($type)->and($owner->id)->toBe($id);
})->with([
    'plain class' => ['App\Models\User:42', 'App\Models\User', '42'],
    'doubled backslashes from a shell' => ['App\\\\Models\\\\User:42', 'App\Models\User', '42'],
    'leading backslash' => ['\App\Models\User:42', 'App\Models\User', '42'],
    'ulid id' => ['App\Models\User:01HZX3K9T5Q7M2A8B6C4D0E1F2', 'App\Models\User', '01HZX3K9T5Q7M2A8B6C4D0E1F2'],
    'an id that contains a colon' => ['App\Models\Device:ios:7', 'App\Models\Device:ios', '7'],
    'padding' => ['  users : 9 ', 'users', '9'],
]);

it('rejects an owner that is not Type:id', function (string $argument): void {
    expect(fn () => OwnerRef::parse($argument))->toThrow(InvalidArgumentException::class);
})->with(['no colon' => ['App\Models\User'], 'no id' => ['App\Models\User:'], 'no type' => [':42'], 'empty' => ['']]);

it('uses the morph alias the tables store when the class has one', function (): void {
    Relation::morphMap(['user' => 'App\Models\User']);

    try {
        expect(OwnerRef::parse('App\Models\User:42')->type)->toBe('user')
            ->and(OwnerRef::parse('user:42')->type)->toBe('user');
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
});

it('renders a forget report per store, with what it cannot reach, and never names the owner id', function (): void {
    $report = new ErasureReport('App\Models\User', 'tenant-a', [
        StoreReport::done('devices', 2, 'registrations deleted'),
        StoreReport::notPresent('live activities', 'module not installed'),
        StoreReport::failed('send log', 'error PDOException'),
    ]);

    $text = implode("\n", $report->lines());

    expect($text)->toContain('devices: 2 row(s) affected (registrations deleted)')
        ->toContain('live activities: not present, nothing touched (module not installed)')
        ->toContain('send log: FAILED, rerun the command')
        ->toContain('Not reached by this command')
        ->toContain('Database backups')
        ->toContain('does not make the application compliant')
        ->and($report->succeeded())->toBeFalse()
        ->and($report->affected())->toBe(2)
        ->and($report->toArray()['stores'][2]['status'])->toBe('failed')
        ->and($report->toArray()['not_reached'])->toBe(ErasureReport::NOT_REACHED);
});
