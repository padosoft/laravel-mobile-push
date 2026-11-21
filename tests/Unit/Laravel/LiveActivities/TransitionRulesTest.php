<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\DecisionKind;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\HourlyLimiter;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\RowSnapshot;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionCommand;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionRules;

const LA_NOW = 1_763_632_800;

function laRow(ActivityState $state = ActivityState::Active, bool $deliverable = true, int $applied = 3, ?int $pending = null, ?int $last = LA_NOW - 10, ?int $expires = null): RowSnapshot
{
    return new RowSnapshot($state, $deliverable, $applied, $pending, $last, localExpiresAt: $expires);
}

function laCmd(LiveActivityEvent $event = LiveActivityEvent::Update, int $seq = 4, bool $fromPending = false): TransitionCommand
{
    return new TransitionCommand($event, $seq, Priority::High, $fromPending);
}

it('decides every state x event x ticket order x time combination', function (RowSnapshot $row, TransitionCommand $command, DecisionKind $kind, ?ReasonCode $code): void {
    $decision = (new TransitionRules(new HourlyLimiter(null)))->decide($row, $command, LA_NOW);

    expect($decision->kind)->toBe($kind);
    if ($code !== null) {
        expect($decision->reason->code)->toBe($code);
    }
    if ($kind === DecisionKind::Send) {
        expect($decision->timestamp)->toBe(LA_NOW);
    }
    if ($kind === DecisionKind::Defer) {
        expect($decision->dueAt)->toBe(($row->lastTimestamp ?? 0) + 1);
    }
})->with([
    'active + update, newer ticket, later second: send' => [laRow(), laCmd(), DecisionKind::Send, null],
    'active + end: send' => [laRow(), laCmd(LiveActivityEvent::End), DecisionKind::Send, null],
    'requested + update: no token yet' => [laRow(ActivityState::Requested, false), laCmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'requested + end: no token yet' => [laRow(ActivityState::Requested, false), laCmd(LiveActivityEvent::End), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'ending + update: terminal for updates' => [laRow(ActivityState::Ending), laCmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'ending + end: resend allowed' => [laRow(ActivityState::Ending), laCmd(LiveActivityEvent::End), DecisionKind::Send, null],
    'ended + update' => [laRow(ActivityState::Ended), laCmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'ended + end' => [laRow(ActivityState::Ended), laCmd(LiveActivityEvent::End), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'expired + update' => [laRow(ActivityState::Expired), laCmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'expired + end' => [laRow(ActivityState::Expired), laCmd(LiveActivityEvent::End), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'active, token quarantined' => [laRow(deliverable: false), laCmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'a start is never a transition' => [laRow(), laCmd(LiveActivityEvent::Start), DecisionKind::Skip, ReasonCode::RequestInvalid],
    'ticket equal to the applied one: stale' => [laRow(applied: 4), laCmd(seq: 4), DecisionKind::Skip, ReasonCode::Cancelled],
    'ticket older than the applied one: stale' => [laRow(applied: 7), laCmd(seq: 4), DecisionKind::Skip, ReasonCode::Cancelled],
    'ticket older than the pending one: stale' => [laRow(applied: 3, pending: 5), laCmd(seq: 4), DecisionKind::Skip, ReasonCode::Cancelled],
    'ticket newer than the pending one: send and supersede it' => [laRow(applied: 3, pending: 4), laCmd(seq: 5), DecisionKind::Send, null],
    'flush of the pending ticket' => [laRow(applied: 3, pending: 4), laCmd(seq: 4, fromPending: true), DecisionKind::Send, null],
    'flush of a retry pending at the applied ticket' => [laRow(applied: 4, pending: 4), laCmd(seq: 4, fromPending: true), DecisionKind::Send, null],
    'flush of a ticket that is no longer the pending one' => [laRow(applied: 3, pending: 6), laCmd(seq: 4, fromPending: true), DecisionKind::Skip, ReasonCode::Cancelled],
    'same second as the last request: coalesce into the next' => [laRow(last: LA_NOW), laCmd(), DecisionKind::Defer, ReasonCode::PolicyFrequencyCapped],
    'last request carried a later second (clock skew between workers): defer' => [laRow(last: LA_NOW + 2), laCmd(), DecisionKind::Defer, null],
    'never sent before: send' => [laRow(last: null), laCmd(), DecisionKind::Send, null],
    'end in the same second: deferred too' => [laRow(last: LA_NOW), laCmd(LiveActivityEvent::End), DecisionKind::Defer, null],
    'flush still in the same second: stays deferred' => [laRow(applied: 3, pending: 4, last: LA_NOW), laCmd(seq: 4, fromPending: true), DecisionKind::Defer, null],
    'closed state wins over ticket order' => [laRow(ActivityState::Ended, applied: 9), laCmd(seq: 4), DecisionKind::Skip, ReasonCode::PolicyIneligible],
]);

it('downgrades only high-priority updates beyond the limit, never ends or explicit low priority', function (): void {
    $rules = new TransitionRules(new HourlyLimiter(1));
    $spent = new RowSnapshot(ActivityState::Active, true, 3, null, LA_NOW - 10, LA_NOW - 60, 1);

    $update = $rules->decide($spent, laCmd(), LA_NOW);
    $end = $rules->decide($spent, laCmd(LiveActivityEvent::End), LA_NOW);
    $low = $rules->decide($spent, new TransitionCommand(LiveActivityEvent::Update, 4, Priority::Normal), LA_NOW);

    expect($update->priority)->toBe(Priority::Normal)->and($update->downgraded)->toBeTrue()
        ->and($end->priority)->toBe(Priority::High)->and($end->downgraded)->toBeFalse()
        ->and($low->priority)->toBe(Priority::Normal)->and($low->downgraded)->toBeFalse()
        ->and($low->hpCount)->toBe(1);
});

it('enforces the local expiry against the clock, whether or not the pruner ran', function (RowSnapshot $row, TransitionCommand $command, DecisionKind $kind, bool $expire): void {
    $decision = (new TransitionRules(new HourlyLimiter(null)))->decide($row, $command, LA_NOW);

    expect($decision->kind)->toBe($kind)->and($decision->expire)->toBe($expire);
    if ($expire) {
        expect($decision->reason->code)->toBe(ReasonCode::PolicyIneligible)
            ->and($decision->reason->hint)->toContain('local expiry');
    }
})->with([
    'active + update past the expiry: skip and mark expired' => [laRow(expires: LA_NOW - 1), laCmd(), DecisionKind::Skip, true],
    'active + update at exactly the expiry second' => [laRow(expires: LA_NOW), laCmd(), DecisionKind::Skip, true],
    'active + end past the expiry: skipped too' => [laRow(expires: LA_NOW - 1), laCmd(LiveActivityEvent::End), DecisionKind::Skip, true],
    'ending + end resend past the expiry' => [laRow(ActivityState::Ending, expires: LA_NOW - 1), laCmd(LiveActivityEvent::End), DecisionKind::Skip, true],
    'requested past the expiry' => [laRow(ActivityState::Requested, false, expires: LA_NOW - 1), laCmd(), DecisionKind::Skip, true],
    'flush of a pending transition past the expiry' => [laRow(applied: 3, pending: 4, expires: LA_NOW - 1), laCmd(seq: 4, fromPending: true), DecisionKind::Skip, true],
    'stale ticket past the expiry: still expires the row' => [laRow(applied: 9, expires: LA_NOW - 1), laCmd(seq: 4), DecisionKind::Skip, true],
    'same second as the last request but expired: skip, not defer' => [laRow(last: LA_NOW, expires: LA_NOW), laCmd(), DecisionKind::Skip, true],
    'already expired row (pruner ran): skip, nothing more to mark' => [laRow(ActivityState::Expired, expires: LA_NOW - 1), laCmd(), DecisionKind::Skip, false],
    'ended row past the expiry: nothing to mark' => [laRow(ActivityState::Ended, expires: LA_NOW - 1), laCmd(), DecisionKind::Skip, false],
    'one second before the expiry: send' => [laRow(expires: LA_NOW + 1), laCmd(), DecisionKind::Send, false],
    'no local expiry (a channel): send' => [laRow(expires: null), laCmd(), DecisionKind::Send, false],
]);
