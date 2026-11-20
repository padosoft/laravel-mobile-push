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

const NOW = 1_763_632_800;

function row(ActivityState $state = ActivityState::Active, bool $deliverable = true, int $applied = 3, ?int $pending = null, ?int $last = NOW - 10): RowSnapshot
{
    return new RowSnapshot($state, $deliverable, $applied, $pending, $last);
}

function cmd(LiveActivityEvent $event = LiveActivityEvent::Update, int $seq = 4, bool $fromPending = false): TransitionCommand
{
    return new TransitionCommand($event, $seq, Priority::High, $fromPending);
}

it('decides every state x event x ticket order x time combination', function (RowSnapshot $row, TransitionCommand $command, DecisionKind $kind, ?ReasonCode $code): void {
    $decision = (new TransitionRules(new HourlyLimiter(null)))->decide($row, $command, NOW);

    expect($decision->kind)->toBe($kind);
    if ($code !== null) {
        expect($decision->reason->code)->toBe($code);
    }
    if ($kind === DecisionKind::Send) {
        expect($decision->timestamp)->toBe(NOW);
    }
    if ($kind === DecisionKind::Defer) {
        expect($decision->dueAt)->toBe(($row->lastTimestamp ?? 0) + 1);
    }
})->with([
    'active + update, newer ticket, later second: send' => [row(), cmd(), DecisionKind::Send, null],
    'active + end: send' => [row(), cmd(LiveActivityEvent::End), DecisionKind::Send, null],
    'requested + update: no token yet' => [row(ActivityState::Requested, false), cmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'requested + end: no token yet' => [row(ActivityState::Requested, false), cmd(LiveActivityEvent::End), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'ending + update: terminal for updates' => [row(ActivityState::Ending), cmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'ending + end: resend allowed' => [row(ActivityState::Ending), cmd(LiveActivityEvent::End), DecisionKind::Send, null],
    'ended + update' => [row(ActivityState::Ended), cmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'ended + end' => [row(ActivityState::Ended), cmd(LiveActivityEvent::End), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'expired + update' => [row(ActivityState::Expired), cmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'expired + end' => [row(ActivityState::Expired), cmd(LiveActivityEvent::End), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'active, token quarantined' => [row(deliverable: false), cmd(), DecisionKind::Skip, ReasonCode::PolicyIneligible],
    'a start is never a transition' => [row(), cmd(LiveActivityEvent::Start), DecisionKind::Skip, ReasonCode::RequestInvalid],
    'ticket equal to the applied one: stale' => [row(applied: 4), cmd(seq: 4), DecisionKind::Skip, ReasonCode::Cancelled],
    'ticket older than the applied one: stale' => [row(applied: 7), cmd(seq: 4), DecisionKind::Skip, ReasonCode::Cancelled],
    'ticket older than the pending one: stale' => [row(applied: 3, pending: 5), cmd(seq: 4), DecisionKind::Skip, ReasonCode::Cancelled],
    'ticket newer than the pending one: send and supersede it' => [row(applied: 3, pending: 4), cmd(seq: 5), DecisionKind::Send, null],
    'flush of the pending ticket' => [row(applied: 3, pending: 4), cmd(seq: 4, fromPending: true), DecisionKind::Send, null],
    'flush of a retry pending at the applied ticket' => [row(applied: 4, pending: 4), cmd(seq: 4, fromPending: true), DecisionKind::Send, null],
    'flush of a ticket that is no longer the pending one' => [row(applied: 3, pending: 6), cmd(seq: 4, fromPending: true), DecisionKind::Skip, ReasonCode::Cancelled],
    'same second as the last request: coalesce into the next' => [row(last: NOW), cmd(), DecisionKind::Defer, ReasonCode::PolicyFrequencyCapped],
    'last request carried a later second (clock skew between workers): defer' => [row(last: NOW + 2), cmd(), DecisionKind::Defer, null],
    'never sent before: send' => [row(last: null), cmd(), DecisionKind::Send, null],
    'end in the same second: deferred too' => [row(last: NOW), cmd(LiveActivityEvent::End), DecisionKind::Defer, null],
    'flush still in the same second: stays deferred' => [row(applied: 3, pending: 4, last: NOW), cmd(seq: 4, fromPending: true), DecisionKind::Defer, null],
    'closed state wins over ticket order' => [row(ActivityState::Ended, applied: 9), cmd(seq: 4), DecisionKind::Skip, ReasonCode::PolicyIneligible],
]);

it('downgrades only high-priority updates beyond the limit, never ends or explicit low priority', function (): void {
    $rules = new TransitionRules(new HourlyLimiter(1));
    $spent = new RowSnapshot(ActivityState::Active, true, 3, null, NOW - 10, NOW - 60, 1);

    $update = $rules->decide($spent, cmd(), NOW);
    $end = $rules->decide($spent, cmd(LiveActivityEvent::End), NOW);
    $low = $rules->decide($spent, new TransitionCommand(LiveActivityEvent::Update, 4, Priority::Normal), NOW);

    expect($update->priority)->toBe(Priority::Normal)->and($update->downgraded)->toBeTrue()
        ->and($end->priority)->toBe(Priority::High)->and($end->downgraded)->toBeFalse()
        ->and($low->priority)->toBe(Priority::Normal)->and($low->downgraded)->toBeFalse()
        ->and($low->hpCount)->toBe(1);
});
