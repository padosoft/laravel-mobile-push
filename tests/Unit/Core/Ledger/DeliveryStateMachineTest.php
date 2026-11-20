<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Ledger\DeliveryEvent as E;
use Padosoft\MobilePush\Core\Ledger\DeliveryState as S;
use Padosoft\MobilePush\Core\Ledger\DeliveryStateMachine;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/*
 * The transition table, written out by hand: every stored state x every event. "-" = ignored (row untouched).
 * Record is expanded below per SendStatus. Columns: Claim, StartAttempt, LeaseLostBeforeSend, LeaseLostRetry,
 * LeaseLostFinal, Expire, Cancel.
 */
const LEDGER_TABLE = [
    //                 Claim       Start       LostBefore  LostRetry   LostFinal         Expire     Cancel
    'pending' => ['leased', '-', '-', '-', '-', 'expired', 'cancelled'],
    'deferred' => ['leased', '-', '-', '-', '-', 'expired', 'cancelled'],
    'leased' => ['-', 'sending', 'pending', '-', '-', '-', 'cancelled'],
    'sending' => ['-', 'sending', '-', 'deferred', 'outcome_unknown', '-', 'sending*'],
    'accepted' => ['-', '-', '-', '-', '-', '-', '-'],
    'rejected' => ['-', '-', '-', '-', '-', '-', '-'],
    'failed' => ['-', '-', '-', '-', '-', '-', '-'],
    'outcome_unknown' => ['-', '-', '-', '-', '-', '-', '-'],
    'expired' => ['-', '-', '-', '-', '-', '-', '-'],
    'skipped' => ['-', '-', '-', '-', '-', '-', '-'],
    'simulated' => ['-', '-', '-', '-', '-', '-', '-'],
    'validated' => ['-', '-', '-', '-', '-', '-', '-'],
    'cancelled' => ['-', '-', '-', '-', '-', '-', '-'],
];

dataset('every state x event', function (): Generator {
    $events = [E::Claim, E::StartAttempt, E::LeaseLostBeforeSend, E::LeaseLostRetry, E::LeaseLostFinal, E::Expire, E::Cancel];
    expect(array_keys(LEDGER_TABLE))->toBe(array_map(static fn (S $s): string => $s->value, S::cases()));

    foreach (LEDGER_TABLE as $from => $row) {
        foreach ($events as $i => $event) {
            yield "{$from} + {$event->value} => {$row[$i]}" => [S::from($from), $event, $row[$i]];
        }
    }
});

it('applies the transition table', function (S $from, E $event, string $expected): void {
    $t = DeliveryStateMachine::apply($from, $event);

    if ($expected === '-') {
        expect($t->allowed)->toBeFalse()->and($t->to)->toBe($from);

        return;
    }

    expect($t->allowed)->toBeTrue()
        ->and($t->to)->toBe(S::from(rtrim($expected, '*')))
        ->and($t->requestsCancel)->toBe(str_ends_with($expected, '*'));
})->with('every state x event');

dataset('every state x recorded status', function (): Generator {
    foreach (S::cases() as $from) {
        foreach (SendStatus::cases() as $status) {
            yield "{$from->value} + record({$status->value})" => [$from, $status];
        }
    }
});

it('records an outcome only on an in-flight row, never over a terminal one', function (S $from, SendStatus $status): void {
    $t = DeliveryStateMachine::apply($from, E::Record, $status);

    if (! in_array($from, [S::Leased, S::Sending], true)) {
        expect($t->allowed)->toBeFalse()->and($t->to)->toBe($from);

        return;
    }

    expect($t->allowed)->toBeTrue()->and($t->to)->toBe(S::fromStatus($status))
        ->and($t->to->toStatus())->toBe($status);
})->with('every state x recorded status');

it('a cancel waiting on an in-flight request turns a planned retry into Cancelled, never a known outcome', function (SendStatus $status, S $expected): void {
    expect(DeliveryStateMachine::apply(S::Sending, E::Record, $status, cancelRequested: true)->to)->toBe($expected);
})->with([
    'deferred becomes cancelled' => [SendStatus::Deferred, S::Cancelled],
    'accepted stays accepted' => [SendStatus::Accepted, S::Accepted],
    'rejected stays rejected' => [SendStatus::Rejected, S::Rejected],
    'unknown stays unknown' => [SendStatus::OutcomeUnknown, S::OutcomeUnknown],
]);

it('a row that already had an unknown outcome never ends as Expired or Cancelled', function (S $from, E $event, ?SendStatus $status, bool $cancel, S $expected): void {
    expect(DeliveryStateMachine::apply($from, $event, $status, $cancel, unknownSeen: true)->to)->toBe($expected);
})->with([
    'deferred retry expires' => [S::Deferred, E::Expire, null, false, S::OutcomeUnknown],
    'deferred retry cancelled' => [S::Deferred, E::Cancel, null, false, S::OutcomeUnknown],
    'leased retry cancelled' => [S::Leased, E::Cancel, null, false, S::OutcomeUnknown],
    'cancel met a planned retry' => [S::Sending, E::Record, SendStatus::Deferred, true, S::OutcomeUnknown],
    'lost lease, retry allowed, but cancelled' => [S::Sending, E::LeaseLostRetry, null, true, S::OutcomeUnknown],
]);

it('derives the guarded-update source states from the same table', function (E $event, array $sources): void {
    expect(DeliveryStateMachine::sourcesOf($event))->toBe(array_map(static fn (string $s): S => S::from($s), $sources));
})->with([
    'claim' => [E::Claim, ['pending', 'deferred']],
    'start' => [E::StartAttempt, ['leased', 'sending']],
    'record' => [E::Record, ['leased', 'sending']],
    'expire' => [E::Expire, ['pending', 'deferred']],
    'cancel' => [E::Cancel, ['pending', 'deferred', 'leased', 'sending']],
]);
