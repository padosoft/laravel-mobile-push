<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Campaigns\CampaignControl;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus as S;

/*
 * The full transition table: every (status, control) pair. "same" = idempotent no-op, null = not allowed.
 */

dataset('transitions', function (): array {
    $table = [
        // control          draft        planning     running      paused       completed    cancelled    failed
        'start' => [S::Planning, 'same', 'same', 'same', null, null, null],
        'pause' => [null, S::Paused, null, 'same', null, null, null],
        'resume' => [null, 'same', 'same', S::Planning, null, null, S::Planning],
        'cancel' => [S::Cancelled, S::Cancelled, S::Cancelled, S::Cancelled, null, 'same', S::Cancelled],
        'finish_planning' => [null, S::Running, 'same', S::Running, 'same', 'same', null],
        'complete' => [null, null, S::Completed, null, 'same', null, null],
        'fail' => [null, S::Failed, null, null, null, null, 'same'],
    ];
    $statuses = [S::Draft, S::Planning, S::Running, S::Paused, S::Completed, S::Cancelled, S::Failed];
    $cases = [];
    foreach ($table as $control => $row) {
        foreach ($statuses as $i => $from) {
            $cases[$from->value.' --'.$control.'-->'] = [$from, CampaignControl::from($control), $row[$i] === 'same' ? $from : $row[$i]];
        }
    }

    return $cases;
});

it('applies the transition table', function (S $from, CampaignControl $control, ?S $to): void {
    expect($from->after($control))->toBe($to);
})->with('transitions');

it('only planning acquires new slices, and only completed and cancelled are final', function (): void {
    expect(array_values(array_filter(S::cases(), static fn (S $s): bool => $s->acquires())))->toBe([S::Planning])
        ->and(array_values(array_filter(S::cases(), static fn (S $s): bool => $s->isFinal())))->toBe([S::Completed, S::Cancelled]);
});
