<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\Delivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\EffectOrigin;
use Padosoft\MobilePush\Core\Ledger\Lease;
use Padosoft\MobilePush\Core\Ledger\LedgerRules;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

/*
 * The row rules every ledger shares (LedgerRules + the store around them), pinned at their boundaries: lease and
 * window ends to the millisecond, version and timestamps of every write, what each stop does to a row that may
 * already have been accepted, batch limits, ordering and scope isolation. Called by ledgerContract(), so each case
 * runs on the in-memory ledger and on the database ledger in both claim strategies.
 */
function ledgerRulesContract(Closure $make): void
{
    $ms = static fn (?DateTimeImmutable $at): ?int => $at === null ? null : LedgerTime::ms($at);
    $now = static fn (H $h): int => LedgerTime::ms($h->clock->now());
    $row = static function (H $h, string $id): Delivery {
        $delivery = $h->ledger->delivery('default', $id);
        expect($delivery)->not->toBeNull();
        \assert($delivery instanceof Delivery);

        return $delivery;
    };
    $destinationOf = static fn (ClaimedDelivery $c) => $c->destination ?? $c->delivery->toSendResult()->destination;
    $settings = static fn (int $maxAttempts = 5, int $maxElapsed = 3600, UnknownOutcomeRetry $unknown = UnknownOutcomeRetry::Replaceable, int $dedupe = 3600, ?Closure $marker = null): LedgerSettings => new LedgerSettings(
        leaseSeconds: 60,
        dedupeSeconds: $dedupe,
        chunkSize: 100,
        retry: new RetryPolicy(maxAttempts: $maxAttempts, maxElapsedSeconds: $maxElapsed, unknownOutcome: $unknown),
        markerIssued: $marker,
    );

    it('rules: the version moves by one on every write except leased -> sending, and each write stamps its own time', function () use ($make, $ms, $now, $row, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $id = (string) $h->rows()['deliveries'][0]['id'];
        $fresh = $row($h, $id);
        expect($fresh->version)->toBe(0)
            ->and($ms($fresh->updatedAt))->toBe($t0)
            ->and($ms($fresh->nextAttemptAt))->toBe($t0);

        $h->clock->advance(1);
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $leased = $row($h, $id);
        expect($claimed->lease->version)->toBe(1)
            ->and($ms($claimed->lease->expiresAt))->toBe($t0 + 61_000)
            ->and($leased->version)->toBe(1)
            ->and($leased->leaseOwner)->toBe('w')
            ->and($ms($leased->leaseExpiresAt))->toBe($t0 + 61_000)
            ->and($ms($leased->updatedAt))->toBe($t0 + 1_000);

        $h->clock->advance(1);
        expect($h->ledger->markAttemptStarted($claimed->lease))->toBeTrue();
        $sending = $row($h, $id);
        expect($sending->state)->toBe(DeliveryState::Sending)
            ->and($sending->version)->toBe(1)
            ->and($ms($sending->attemptStartedAt))->toBe($t0 + 2_000)
            ->and($ms($sending->updatedAt))->toBe($t0 + 2_000);

        $h->clock->advance(1);
        $busy = new SendResult($destinationOf($claimed), SendStatus::Deferred, Reason::of(ReasonCode::ProviderUnavailable, 'ServiceUnavailable', 'Apple is busy'), 1, 'apns-1', 503,
            nextAttemptAt: $h->clock->now()->modify('+30 seconds'), deliveryId: $id);
        expect($h->ledger->record($claimed->lease, $busy, $h->budgetAfterOneAttempt()))->toBe(Recording::Recorded);
        $deferred = $row($h, $id);
        expect($deferred->version)->toBe(2)
            ->and($ms($deferred->updatedAt))->toBe($t0 + 3_000)
            ->and($deferred->leaseOwner)->toBeNull()
            ->and($deferred->leaseExpiresAt)->toBeNull()
            ->and($deferred->attemptStartedAt)->toBeNull()
            ->and($ms($deferred->nextAttemptAt))->toBe($t0 + 33_000)
            ->and($deferred->acceptedAt)->toBeNull()
            ->and($deferred->reason->hint)->toBe('Apple is busy')
            ->and($deferred->httpStatus)->toBe(503);

        $h->clock->advance(30);
        $again = $h->ledger->claimDue('default', 'w', 1)[0];
        expect($again->lease->version)->toBe(3);
        $h->ledger->markAttemptStarted($again->lease);
        $h->clock->advance(1);
        $h->ledger->record($again->lease, $h->accepted($again, 'apns-2'), $again->budget()?->withAttempt());
        $accepted = $row($h, $id);
        expect($accepted->state)->toBe(DeliveryState::Accepted)
            ->and($accepted->version)->toBe(4)
            ->and($ms($accepted->acceptedAt))->toBe($t0 + 34_000)
            ->and($ms($accepted->updatedAt))->toBe($t0 + 34_000)
            ->and($accepted->nextAttemptAt)->toBeNull()
            ->and($accepted->reason->code)->toBe(ReasonCode::None)
            ->and($accepted->reason->rawReason)->toBeNull()
            ->and($accepted->reason->hint)->toBeNull()
            ->and($accepted->httpStatus)->toBe(200)
            ->and($accepted->providerMessageId)->toBe('apns-2')
            ->and($accepted->attempts)->toBe(2);
    });

    it('rules: lease boundary: one millisecond before its end the worker may start; at its end it may not and the row is reclaimed', function () use ($make, $now): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(2));
        [$a, $b] = $h->ledger->claimDue('default', 'w', 2);

        $h->advanceMs(59_999);
        expect($h->ledger->markAttemptStarted($a->lease))->toBeTrue()
            ->and($h->ledger->scopesWithWork())->toBe([])
            ->and($h->ledger->reclaimExpiredLeases('default')->total())->toBe(0);

        $h->advanceMs(1);
        expect($now($h))->toBe($t0 + 60_000)
            ->and($h->ledger->markAttemptStarted($b->lease))->toBeFalse()
            ->and($h->ledger->scopesWithWork())->toBe(['default']);
        $report = $h->ledger->reclaimExpiredLeases('default');
        expect($report->requeued)->toBe(1)
            ->and($report->retriedAfterUnknown)->toBe(0)
            ->and($report->endedUnknown)->toBe(1)
            ->and($h->ledger->delivery('default', $a->id())?->state)->toBe(DeliveryState::OutcomeUnknown)
            ->and($h->ledger->delivery('default', $b->id())?->state)->toBe(DeliveryState::Pending);
    });

    it('rules: a lease of an explicit length ends after that length, not after the configured one', function () use ($make, $ms, $now): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $claimed = $h->ledger->claimDue('default', 'w', 1, null, 5)[0];

        expect($ms($claimed->lease->expiresAt))->toBe($t0 + 5_000)
            ->and($ms($claimed->delivery->leaseExpiresAt))->toBe($t0 + 5_000);
        $h->clock->advance(5);
        expect($h->ledger->markAttemptStarted($claimed->lease))->toBeFalse()
            ->and($h->ledger->claimDue('default', 'w2', 1))->toHaveCount(1);
    });

    it('rules: a worker\'s own earlier lease is stale once the row was reclaimed and claimed again by the same worker', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $old = $h->ledger->claimDue('default', 'worker-a', 1)[0];
        $h->expireLeases();
        $new = $h->ledger->claimDue('default', 'worker-a', 1)[0];

        expect($new->lease->version)->not->toBe($old->lease->version)
            ->and($h->ledger->markAttemptStarted($old->lease))->toBeFalse()
            ->and($h->ledger->record($old->lease, $h->accepted($old)))->toBe(Recording::IgnoredStaleLease)
            ->and($h->ledger->markAttemptStarted($new->lease))->toBeTrue()
            ->and($h->ledger->record($new->lease, $h->accepted($new), $h->budgetAfterOneAttempt()))->toBe(Recording::Recorded);
    });

    it('rules: a lease for a row that does not exist in that scope records nothing', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $elsewhere = new Lease($claimed->id(), 'tenant-b', 'w', $claimed->lease->version, $claimed->lease->expiresAt);
        $missing = new Lease('01JZZZZZZZZZZZZZZZZZZZZZZZ', 'default', 'w', 1, $claimed->lease->expiresAt);

        expect($h->ledger->markAttemptStarted($elsewhere))->toBeFalse()
            ->and($h->ledger->record($elsewhere, $h->accepted($claimed)))->toBe(Recording::NotFound)
            ->and($h->ledger->record($missing, $h->accepted($claimed)))->toBe(Recording::NotFound)
            ->and($h->ledger->delivery('default', $claimed->id())?->state)->toBe(DeliveryState::Leased);
    });

    it('rules: a row returned to the queue before sending keeps its place: it is due since its original time', function () use ($make, $now): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $first = $h->ledger->recordIntent(H::intent(H::alert(), 'first'), [H::token(1)])->effect;
        $h->clock->advance(10);
        $h->ledger->recordIntent(H::intent(H::alert('second'), 'second'), [H::token(2)]);

        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        expect($claimed->effectId())->toBe($first->id);
        $h->expireLeases();   // now t0 + 71 s
        $again = $h->ledger->claimDue('default', 'w', 1)[0];

        expect($again->id())->toBe($claimed->id())
            ->and(LedgerTime::ms($again->delivery->nextAttemptAt ?? $h->clock->now()))->toBe($t0);
    });

    it('rules: a lost lease after the request left follows the unknown-outcome policy, the attempt limit and the deadline', function (UnknownOutcomeRetry $policy, bool $live, int $maxAttempts, int $maxElapsed, DeliveryState $expected) use ($make, $ms, $now, $row, $settings): void {
        /** @var H $h */
        $h = $make()->withSettings($settings($maxAttempts, $maxElapsed, $policy));
        $t0 = $now($h);
        $h->ledger->recordIntent(H::intent($live ? H::liveUpdate() : H::alert()), [H::token(1)]);

        // Attempt 1 at t0: the provider is busy, retry in 30 s.
        $first = $h->ledger->claimDue('default', 'a', 1)[0];
        $h->ledger->markAttemptStarted($first->lease);
        $h->ledger->record($first->lease, $h->deferred($first, 30), $h->budgetAfterOneAttempt());

        // Attempt 2 at t0 + 30 s leaves, then the worker dies: its lease ends at t0 + 90 s.
        $h->clock->advance(30);
        $second = $h->ledger->claimDue('default', 'b', 1)[0];
        $h->ledger->markAttemptStarted($second->lease);
        $h->clock->advance(60);
        $report = $h->ledger->reclaimExpiredLeases('default');

        $lost = $row($h, $second->id());
        $retried = $expected === DeliveryState::Deferred;
        expect($lost->state)->toBe($expected)
            ->and($report->retriedAfterUnknown)->toBe($retried ? 1 : 0)
            ->and($report->endedUnknown)->toBe($retried ? 0 : 1)
            ->and($lost->attempts)->toBe(2)
            ->and($lost->budget?->attempts)->toBe(2)
            ->and($lost->budget?->unknownOutcomeSeen)->toBeTrue()
            ->and($ms($lost->budget?->startedAt))->toBe($t0)
            ->and($lost->reason->code)->toBe(ReasonCode::TransportUnknown)
            ->and($lost->reason->rawReason)->toBeNull()
            ->and($lost->reason->hint)->toBe(LedgerRules::LOST_LEASE_HINT)
            ->and($lost->httpStatus)->toBeNull()
            ->and($lost->leaseOwner)->toBeNull()
            ->and($lost->leaseExpiresAt)->toBeNull()
            ->and($lost->attemptStartedAt)->toBeNull()
            ->and($lost->version)->toBe(4)
            ->and($ms($lost->updatedAt))->toBe($t0 + 90_000)
            ->and($ms($lost->nextAttemptAt))->toBe($retried ? $t0 + 90_000 : null);

        if ($retried) {
            // The earlier attempt may have been accepted: stopping the retry now cannot call it cancelled.
            expect($h->ledger->cancelEffect('default', $lost->effectId))->toBe(1)
                ->and($row($h, $lost->id)->state)->toBe(DeliveryState::OutcomeUnknown);
        }
    })->with([
        'policy never, live update' => [UnknownOutcomeRetry::Never, true, 5, 3600, DeliveryState::OutcomeUnknown],
        'policy replaceable, alert' => [UnknownOutcomeRetry::Replaceable, false, 5, 3600, DeliveryState::OutcomeUnknown],
        'policy replaceable, live update' => [UnknownOutcomeRetry::Replaceable, true, 5, 3600, DeliveryState::Deferred],
        'policy always, alert' => [UnknownOutcomeRetry::Always, false, 5, 3600, DeliveryState::Deferred],
        'the lost attempt was the last allowed (2 of 2)' => [UnknownOutcomeRetry::Always, false, 2, 3600, DeliveryState::OutcomeUnknown],
        'one attempt left (2 of 3)' => [UnknownOutcomeRetry::Always, false, 3, 3600, DeliveryState::Deferred],
        'reclaimed exactly at the deadline' => [UnknownOutcomeRetry::Always, false, 5, 90, DeliveryState::OutcomeUnknown],
        'reclaimed one second before the deadline' => [UnknownOutcomeRetry::Always, false, 5, 91, DeliveryState::Deferred],
    ]);

    it('rules: a lost attempt without a stored budget starts its budget at the attempt start, not at a later write', function () use ($make, $ms, $now, $row): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($claimed->lease);

        // A cancel meets the request in flight: it only flags the row (no version bump, the lease stays valid).
        $h->clock->advance(10);
        expect($h->ledger->cancelEffect('default', $claimed->effectId()))->toBe(1);
        $flagged = $row($h, $claimed->id());
        expect($flagged->state)->toBe(DeliveryState::Sending)
            ->and($flagged->cancelRequested)->toBeTrue()
            ->and($flagged->version)->toBe(1)
            ->and($ms($flagged->updatedAt))->toBe($t0 + 10_000);

        $h->clock->advance(50);
        $h->ledger->reclaimExpiredLeases('default');
        $lost = $row($h, $claimed->id());
        expect($lost->state)->toBe(DeliveryState::OutcomeUnknown)
            ->and($ms($lost->budget?->startedAt))->toBe($t0)
            ->and($lost->budget?->attempts)->toBe(1);
    });

    it('rules: stopping a waiting row ends it Expired or Cancelled, but OutcomeUnknown once an earlier attempt may have been accepted', function (string $stop, bool $unknownSeen) use ($make, $ms, $now, $row): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $effect = $h->ledger->recordIntent(H::intent(H::alert(), expiresAt: $h->clock->now()->modify('+600 seconds')), [H::token(1)])->effect;
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($claimed->lease);
        $budget = $h->budgetAfterOneAttempt();
        $h->ledger->record($claimed->lease, $h->deferred($claimed, 120), $unknownSeen ? $budget->withUnknownOutcome() : $budget);

        if ($stop === 'expire') {
            $h->clock->advance(600);
            expect($h->ledger->expireDue('default'))->toBe(1);
        } else {
            $h->clock->advance(10);
            expect($h->ledger->cancelEffect('default', $effect->id))->toBe(1);
        }

        $stopped = $row($h, $claimed->id());
        $final = $unknownSeen ? DeliveryState::OutcomeUnknown : ($stop === 'expire' ? DeliveryState::Expired : DeliveryState::Cancelled);
        $code = $unknownSeen ? ReasonCode::TransportUnknown : ($stop === 'expire' ? ReasonCode::Expired : ReasonCode::Cancelled);
        expect($stopped->state)->toBe($final)
            ->and($stopped->reason->code)->toBe($code)
            ->and($stopped->nextAttemptAt)->toBeNull()
            ->and($stopped->version)->toBe(3)
            ->and($ms($stopped->updatedAt))->toBe($t0 + ($stop === 'expire' ? 600_000 : 10_000));
        if ($unknownSeen) {
            expect($stopped->reason->hint)->toBe(LedgerRules::LOST_LEASE_HINT);
        } else {
            expect($stopped->reason->hint)->not->toBeNull()->not->toBe(LedgerRules::LOST_LEASE_HINT);
        }
        if ($stop === 'cancel') {
            expect($stopped->cancelRequested)->toBeTrue();
        }
        if ($stop === 'cancel' && ! $unknownSeen) {
            expect($stopped->reason->hint)->toBe('Cancelled.');
        }
    })->with([
        'expiry, nothing may have been accepted' => ['expire', false],
        'expiry after an unknown outcome' => ['expire', true],
        'cancel, nothing may have been accepted' => ['cancel', false],
        'cancel after an unknown outcome' => ['cancel', true],
    ]);

    it('rules: cancelling a leased row frees the lease and the worker can neither start nor record', function () use ($make, $row): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(2))->effect;
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->clock->advance(5);

        expect($h->ledger->cancelEffect('default', $effect->id))->toBe(2);
        foreach ($h->rows()['deliveries'] as $stored) {
            $cancelled = $row($h, (string) $stored['id']);
            expect($cancelled->state)->toBe(DeliveryState::Cancelled)
                ->and($cancelled->leaseOwner)->toBeNull()
                ->and($cancelled->leaseExpiresAt)->toBeNull()
                ->and($cancelled->nextAttemptAt)->toBeNull()
                ->and($cancelled->cancelRequested)->toBeTrue();
        }
        expect($h->ledger->markAttemptStarted($claimed->lease))->toBeFalse()
            ->and($h->ledger->record($claimed->lease, $h->accepted($claimed)))->toBe(Recording::IgnoredFinal);
    });

    it('rules: a cancel leaves final rows untouched and counts only the rows it stopped', function () use ($make, $row): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(2))->effect;
        $done = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($done->lease);
        $h->ledger->record($done->lease, $h->accepted($done), $h->budgetAfterOneAttempt());
        $before = $row($h, $done->id());

        $h->clock->advance(5);
        expect($h->ledger->cancelEffect('default', $effect->id))->toBe(1);
        $after = $row($h, $done->id());
        expect($after->state)->toBe(DeliveryState::Accepted)
            ->and($after->version)->toBe($before->version)
            ->and($after->reason->code)->toBe(ReasonCode::None)
            ->and($after->cancelRequested)->toBeFalse()
            ->and($after->updatedAt)->toEqual($before->updatedAt)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Cancelled))->toBe(1);
    });

    it('rules: cancelOwner stops only the rows of that exact owner type and id, in that scope', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $admin = H::token(3)->withRef(new DestinationRef('3', 1, 'App\\Models\\Admin', '1'));
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::device(1, ownerId: '1'), H::device(2, ownerId: '2'), $admin])->effect;
        $beta = $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'beta'), [H::device(1, ownerId: '1')])->effect;

        expect($h->ledger->cancelOwner('default', 'App\\Models\\User', '1'))->toBe(1)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Cancelled))->toBe(1)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Pending))->toBe(2)
            ->and($h->ledger->counts('beta', $beta->id)->of(DeliveryState::Pending))->toBe(1);
        $cancelled = array_values(array_filter($h->rows()['deliveries'], static fn (array $r): bool => $r['state'] === 'cancelled'));
        expect($cancelled)->toHaveCount(1)
            ->and($cancelled[0]['device_id'])->toBe('1')
            ->and($cancelled[0]['scope'])->toBe('default');
    });

    it('rules: forget erases only that owner\'s effects in that scope, and stamps the erasure on final rows too', function () use ($make, $ms, $now, $row): void {
        /** @var H $h */
        $h = $make();
        $user = new EffectOrigin('App\\Models\\User', '1', 'App\\Notifications\\Welcome');
        $mine = $h->ledger->recordIntent(new EffectIntent(H::binding(), H::alert('Hi Alice'), 'mine', origin: $user), [H::device(1)])->effect;
        $admin = $h->ledger->recordIntent(new EffectIntent(H::binding(), H::alert('Hi admin'), 'admin', origin: new EffectOrigin('App\\Models\\Admin', '1', null)), [H::fcm('admin')])->effect;
        $beta = $h->ledger->recordIntent(new EffectIntent(H::binding('beta'), H::alert('Hi beta'), 'beta', origin: $user), [H::fcm('beta')])->effect;
        $h->acceptAll('default', 'w', 10, $mine->id);
        $deliveryId = (string) array_values(array_filter($h->rows()['deliveries'], static fn (array $r): bool => $r['effect_id'] === $mine->id))[0]['id'];

        $h->clock->advance(10);
        $at = $now($h);
        expect($h->ledger->forgetOwner('default', 'App\\Models\\User', '1'))->toBe(1)
            ->and($h->ledger->message('default', $mine->id))->toBeNull()
            ->and($h->ledger->message('default', $admin->id)?->body)->toBe('Hi admin')
            ->and($h->ledger->message('beta', $beta->id)?->body)->toBe('Hi beta');

        $erased = $h->deliveryRow($deliveryId);
        $effectRow = array_values(array_filter($h->rows()['effects'], static fn (array $r): bool => $r['id'] === $mine->id))[0];
        expect($erased['device_generation'])->toBeNull()
            ->and($row($h, $deliveryId)->state)->toBe(DeliveryState::Accepted)
            ->and($ms($row($h, $deliveryId)->updatedAt))->toBe($at)
            ->and((int) $effectRow['updated_at'])->toBe($at);
    });

    it('rules: dedupe window boundary: one millisecond before its end a replay is the same effect, at its end the key is free', function () use ($make, $ms, $now): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $first = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        expect($ms($first->effect->dedupeUntil))->toBe($t0 + 3_600_000);

        $h->advanceMs(3_599_999);
        expect($h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->created)->toBeFalse();
        expect(fn () => $h->ledger->recordIntent(H::intent(H::alert('changed')), [H::token(1)]))->toThrow(IdempotencyConflictException::class);

        $h->advanceMs(1);
        $again = $h->ledger->recordIntent(H::intent(H::alert('changed')), [H::token(1)]);
        expect($again->created)->toBeTrue()
            ->and($again->previousEffectId)->toBe($first->effect->id);
    });

    it('rules: an effect reads back with what its intent said, its own window included, and only in its scope', function () use ($make, $ms, $now): void {
        /** @var H $h */
        $h = $make();
        $t0 = $now($h);
        $binding = H::binding('acme');
        $intent = new EffectIntent($binding, H::alert(), 'k-1', expiresAt: $h->clock->now()->modify('+600 seconds'), policy: 'quiet-hours',
            origin: new EffectOrigin('App\\Models\\User', '7', 'App\\Notifications\\Shipped'), campaignId: 'camp-3', dedupeSeconds: 120);
        $id = $h->ledger->recordIntent($intent, [H::token(1)])->effect->id;

        $effect = $h->ledger->effect('acme', $id);
        expect($effect)->not->toBeNull()
            ->and($effect?->id)->toBe($id)
            ->and($effect?->effectKey)->toBe('k-1')
            ->and($effect?->policy)->toBe('quiet-hours')
            ->and($effect?->origin?->notifiableId)->toBe('7')
            ->and($effect?->origin?->notificationClass)->toBe('App\\Notifications\\Shipped')
            ->and($effect?->campaignId)->toBe('camp-3')
            ->and($ms($effect?->expiresAt))->toBe($t0 + 600_000)
            ->and($ms($effect?->dedupeUntil))->toBe($t0 + 120_000)
            ->and($effect?->binding->identity())->toBe($binding->identity())
            ->and($h->ledger->effect('default', $id))->toBeNull()
            ->and($h->rows()['effects'][0]['binding_identity'])->toBe($binding->identity())
            ->and((int) $h->rows()['effects'][0]['updated_at'])->toBe($t0);

        // The intent's own window (120 s), not the configured one (3600 s), frees the key.
        $h->clock->advance(120);
        expect($h->ledger->recordIntent($intent, [H::token(1)])->created)->toBeTrue();
    });

    it('rules: claims come in (next_attempt_at, id) order and stop at the limit; a limit of 0 or less claims nothing', function () use ($make, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(6));
        $all = array_map(static fn (array $r): string => (string) $r['id'], $h->rows()['deliveries']);
        sort($all, SORT_STRING);

        expect($h->ledger->claimDue('default', 'w', 0))->toBe([])
            ->and($h->ledger->claimDue('default', 'w', -1))->toBe([]);
        $four = $h->ledger->claimDue('default', 'w', 4);
        expect(array_map(static fn (ClaimedDelivery $c): string => $c->id(), $four))->toBe(array_slice($all, 0, 4));

        // The first claimed waits 50 s, the second 20 s; the other two are accepted.
        foreach ($four as $i => $c) {
            $h->ledger->markAttemptStarted($c->lease);
            $result = $i < 2
                ? new SendResult($destinationOf($c), SendStatus::Deferred, Reason::of(ReasonCode::ProviderUnavailable), 1, nextAttemptAt: $h->clock->now()->modify($i === 0 ? '+50 seconds' : '+20 seconds'), deliveryId: $c->id())
                : $h->accepted($c);
            $h->ledger->record($c->lease, $result, $h->budgetAfterOneAttempt());
        }
        $h->clock->advance(60);

        expect($h->claimIds('default', 'w', 4))->toBe([$all[4], $all[5], $all[1], $all[0]]);
    });

    it('rules: claimDue sweeps at most four times its limit of expired leases before claiming', function (int $limit) use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(10))->effect;
        expect($h->ledger->claimDue('default', 'worker-a', 10))->toHaveCount(10);
        $h->expireLeases();

        expect($h->ledger->claimDue('default', 'worker-b', $limit))->toHaveCount($limit)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Pending))->toBe(3 * $limit)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Leased))->toBe(10 - 3 * $limit);
    })->with([1, 2]);

    it('rules: claimDue expires at most four times its limit, and a row at its exact expiry is never claimed', function (int $limit) use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert(), expiresAt: $h->clock->now()->modify('+60 seconds')), H::tokens(10))->effect;
        $h->clock->advance(60);

        expect($h->ledger->claimDue('default', 'w', $limit))->toBe([])
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Expired))->toBe(4 * $limit)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Pending))->toBe(10 - 4 * $limit);
    })->with([1, 2]);

    it('rules: reclaim and expiry work at most $limit rows per call, from the exact instant, and never touch final or in-flight rows', function () use ($make, $row): void {
        /** @var H $h */
        $h = $make();
        // Rows that must be skipped come first: another scope, a row without expiry.
        $h->ledger->recordIntent(H::intent(H::alert(), 'other-scope', 'beta', expiresAt: $h->clock->now()->modify('+10 seconds')), [H::token(90)]);
        $h->ledger->recordIntent(H::intent(H::alert(), 'no-expiry'), [H::token(91)]);
        $done = $h->ledger->recordIntent(H::intent(H::alert(), 'done', expiresAt: $h->clock->now()->modify('+10 seconds')), [H::token(92)])->effect;
        $h->acceptAll('default', 'w', 10, $done->id);
        $working = $h->ledger->recordIntent(H::intent(H::alert(), 'working', expiresAt: $h->clock->now()->modify('+10 seconds')), [H::token(93)])->effect;
        $inFlight = $h->ledger->claimDue('default', 'w', 1, $working->id)[0];
        $h->ledger->recordIntent(H::intent(H::alert(), 'three', expiresAt: $h->clock->now()->modify('+10 seconds')), H::tokens(3));

        $h->clock->advance(10);
        expect($h->ledger->expireDue('default', 2))->toBe(2)
            ->and($h->ledger->expireDue('default', 2))->toBe(1)
            ->and($h->ledger->expireDue('default', 2))->toBe(0)
            ->and($row($h, (string) array_values(array_filter($h->rows()['deliveries'], static fn (array $r): bool => $r['effect_id'] === $done->id))[0]['id'])->reason->code)->toBe(ReasonCode::None)
            ->and($h->ledger->markAttemptStarted($inFlight->lease))->toBeTrue();

        $more = $h->ledger->recordIntent(H::intent(H::alert(), 'leases'), H::tokens(3, 20))->effect;
        expect($h->ledger->claimDue('default', 'w', 10, $more->id))->toHaveCount(3);
        $h->expireLeases();
        expect($h->ledger->reclaimExpiredLeases('default', 2)->total())->toBe(2)
            ->and($h->ledger->reclaimExpiredLeases('default', 2)->total())->toBe(2)   // the third lease and the in-flight row
            ->and($h->ledger->reclaimExpiredLeases('default', 2)->total())->toBe(0);
    });

    it('rules: reclaim and expiry do at most 500 rows per call by default', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert(), expiresAt: $h->clock->now()->modify('+120 seconds')), H::tokens(501))->effect;
        expect($h->ledger->claimDue('default', 'w', 501))->toHaveCount(501);

        $h->expireLeases();
        expect($h->ledger->reclaimExpiredLeases('default')->requeued)->toBe(500)
            ->and($h->ledger->reclaimExpiredLeases('default')->requeued)->toBe(1);

        $h->clock->advance(60);
        expect($h->ledger->expireDue('default'))->toBe(500)
            ->and($h->ledger->expireDue('default'))->toBe(1)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Expired))->toBe(501);
    });

    it('rules: a replaceable push cancelled while in flight is reported as ended unknown when its lease is lost, not as retried', function () use ($make, $row): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::liveUpdate()), [H::token(1)])->effect;
        $claimed = $h->ledger->claimDue('default', 'a', 1)[0];
        $h->ledger->markAttemptStarted($claimed->lease);
        $h->ledger->cancelEffect('default', $effect->id);     // the request left: only the flag is set

        $h->clock->advance(600);                              // the worker died: the lease is long over
        $report = $h->ledger->reclaimExpiredLeases('default');

        // The message is replaceable, so without the cancellation it would be attempted again. It is not: the row
        // ends unknown, and the report must say what happened to it.
        expect($row($h, $claimed->id())->state)->toBe(DeliveryState::OutcomeUnknown)
            ->and($report->retriedAfterUnknown)->toBe(0)
            ->and($report->endedUnknown)->toBe(1);
    });

    it('rules: the reclaim report counts every row by what happened to it', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert(), 'alerts'), H::tokens(4));
        $h->ledger->recordIntent(H::intent(H::liveUpdate(), 'live'), H::tokens(2, 10));
        $claimed = $h->ledger->claimDue('default', 'w', 10);
        $started = 0;
        foreach ($claimed as $c) {
            // leave one alert leased (requeued); start the rest: 3 alerts end unknown, 2 live updates are retried
            if ($c->delivery->replaceable || $started++ < 3) {
                $h->ledger->markAttemptStarted($c->lease);
            }
        }
        $h->expireLeases();
        $report = $h->ledger->reclaimExpiredLeases('default');

        expect($report->requeued)->toBe(1)
            ->and($report->retriedAfterUnknown)->toBe(2)
            ->and($report->endedUnknown)->toBe(3);
    });

    it('rules: a record without a budget keeps the stored budget, the provider id and the attempts already counted', function () use ($make, $row, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $first = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($first->lease);
        $busy = new SendResult($destinationOf($first), SendStatus::Deferred, Reason::of(ReasonCode::ProviderUnavailable), 1, 'apns-1', 503, $h->clock->now()->modify('+10 seconds'), deliveryId: $first->id());
        $h->ledger->record($first->lease, $busy, $h->budgetAfterOneAttempt());

        $h->clock->advance(10);
        $second = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($second->lease);
        $failed = new SendResult($destinationOf($second), SendStatus::Failed, Reason::of(ReasonCode::ProviderUnavailable), 0, null, 500, deliveryId: $second->id());
        expect($h->ledger->record($second->lease, $failed))->toBe(Recording::Recorded);

        $final = $row($h, $second->id());
        expect($final->state)->toBe(DeliveryState::Failed)
            ->and($final->budget?->attempts)->toBe(1)
            ->and($final->providerMessageId)->toBe('apns-1')
            ->and($final->attempts)->toBe(1)
            ->and($final->httpStatus)->toBe(500);
    });

    it('rules: a skip before the network counts no attempt', function () use ($make, $row, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $skipped = new SendResult($destinationOf($claimed), SendStatus::Skipped, Reason::of(ReasonCode::Unknown, 'gate'), 0, deliveryId: $claimed->id());

        expect($h->ledger->record($claimed->lease, $skipped))->toBe(Recording::Recorded)
            ->and($row($h, $claimed->id())->attempts)->toBe(0)
            ->and($row($h, $claimed->id())->state)->toBe(DeliveryState::Skipped);
    });

    it('rules: an invalidated token keeps the provider verdict: destination action and invalidation time', function () use ($make, $row, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($claimed->lease);
        $gone = new SendResult($destinationOf($claimed), SendStatus::Rejected, Reason::of(ReasonCode::DestinationRejected, 'Unregistered'), 1, 'apns-9', 410,
            destinationAction: DestinationAction::Invalidate, invalidatedAtMs: 1_763_632_800_123, deliveryId: $claimed->id());
        $h->ledger->record($claimed->lease, $gone, $h->budgetAfterOneAttempt());

        $rejected = $row($h, $claimed->id());
        expect($rejected->state)->toBe(DeliveryState::Rejected)
            ->and($rejected->destinationAction)->toBe(DestinationAction::Invalidate)
            ->and($rejected->invalidatedAtMs)->toBe(1_763_632_800_123)
            ->and($rejected->reason->rawReason)->toBe('Unregistered')
            ->and($rejected->httpStatus)->toBe(410);
    });

    it('rules: a raw destination that no longer decrypts is skipped at claim, before any request, and the rows after it are still claimed', function () use ($make, $row): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(3));
        $rows = $h->rows()['deliveries'];
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        // The first row in claim order now holds another row's ciphertext: same kind, same scope, other logical key.
        $h->patchDelivery((string) $rows[0]['id'], ['destination' => $rows[1]['destination']]);

        $claimed = $h->ledger->claimDue('default', 'w', 10);
        $skipped = $row($h, (string) $rows[0]['id']);
        expect(array_map(static fn (ClaimedDelivery $c): string => $c->id(), $claimed))->toBe([(string) $rows[1]['id'], (string) $rows[2]['id']])
            ->and($skipped->state)->toBe(DeliveryState::Skipped)
            ->and($skipped->reason->code)->toBe(ReasonCode::Unknown)
            ->and($skipped->reason->rawReason)->toBe('destination_unreadable')
            ->and($skipped->reason->hint)->not->toBeNull()
            ->and($skipped->nextAttemptAt)->toBeNull()
            ->and($skipped->leaseOwner)->toBeNull()
            ->and($skipped->leaseExpiresAt)->toBeNull()
            ->and($skipped->version)->toBe(2)
            ->and($skipped->attempts)->toBe(0);
    });

    it('rules: receipt markers: a valid sender id the process issued a marker for counts, any other id does not', function () use ($make, $settings, $destinationOf): void {
        /** @var H $h */
        $h = $make()->withSettings($settings(marker: static fn (string $id): bool => true));
        $store = $h->ledger;
        \assert($store instanceof ClientReceiptStore);
        $valid = $h->ledger->recordIntent(H::intent(H::alert(), 'valid'), [H::token(1)])->effect;
        $invalid = $h->ledger->recordIntent(H::intent(H::alert(), 'invalid'), [H::token(2)])->effect;
        $legacy = $h->ledger->recordIntent(H::intent(H::alert(), 'legacy'), [H::token(3)])->effect;
        $legacyRow = (string) array_values(array_filter($h->rows()['deliveries'], static fn (array $r): bool => $r['effect_id'] === $legacy->id))[0]['id'];
        $h->patchDelivery($legacyRow, ['message_id' => null]);   // a row written before message ids existed
        $sender = '22222222-2222-4222-8222-222222222222';

        foreach ($h->ledger->claimDue('default', 'w', 10) as $c) {
            $h->ledger->markAttemptStarted($c->lease);
            $id = match ($c->effectId()) {
                $valid->id => (string) $c->providerMessageId(),
                $invalid->id => 'not-a-uuid',
                default => $sender,
            };
            $h->ledger->record($c->lease, new SendResult($destinationOf($c), SendStatus::Accepted, Reason::none(), 1, 'p', 200, deliveryId: $c->id(), meta: ['message_id' => $id]), $h->budgetAfterOneAttempt());
        }

        expect($store->effectFunnel('default', $valid->id)->acceptedWithMarker)->toBe(1)
            ->and($store->effectFunnel('default', $invalid->id)->acceptedWithMarker)->toBe(0)
            ->and($store->effectFunnel('default', $legacy->id)->acceptedWithMarker)->toBe(1)
            ->and($h->ledger->delivery('default', $legacyRow)?->messageId)->toBe($sender);
    });

    it('rules: a message id names the newest delivery that carries it', function (bool $newestFirst) use ($make): void {
        /** @var H $h */
        $h = $make();
        $store = $h->ledger;
        \assert($store instanceof ClientReceiptStore);
        $h->ledger->recordIntent(H::intent(H::alert(), 'a'), [H::token(1)]);
        $h->clock->advance(5);
        $h->ledger->recordIntent(H::intent(H::alert(), 'b'), [H::token(2)]);
        [$a, $b] = array_map(static fn (array $r): array => $r, $h->rows()['deliveries']);
        if ((int) $a['created_at'] > (int) $b['created_at']) {
            [$a, $b] = [$b, $a];
        }
        $h->patchDelivery((string) $b['id'], ['message_id' => $a['message_id']]);
        if ($newestFirst) {
            $h->patchDelivery((string) $a['id'], ['created_at' => (int) $b['created_at'] + 1000]);
        }

        expect($store->deliveryByMessageId('default', (string) $a['message_id'])?->id)->toBe($newestFirst ? $a['id'] : $b['id']);
    })->with(['newest written last' => [false], 'newest written first' => [true]]);

    it('rules: results stream only the rows of their effect, in id order', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $mine = $h->ledger->recordIntent(H::intent(H::alert(), 'mine'), H::tokens(8))->effect;
        $h->ledger->recordIntent(H::intent(H::alert(), 'other'), H::tokens(3, 50));

        $ids = array_map(static fn (SendResult $r): ?string => $r->deliveryId, iterator_to_array($h->ledger->results('default', $mine->id), false));
        $expected = array_map(static fn (array $r): string => (string) $r['id'], array_filter($h->rows()['deliveries'], static fn (array $r): bool => $r['effect_id'] === $mine->id));
        sort($expected, SORT_STRING);

        expect($ids)->toBe($expected);
    });

    it('rules: a campaign funnel counts only the rows of that campaign in that scope', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $store = $h->ledger;
        \assert($store instanceof ClientReceiptStore);
        $h->ledger->recordIntent(H::intent(H::alert(), 'one', campaign: 'camp-1'), H::tokens(2));
        $h->ledger->recordIntent(H::intent(H::alert(), 'two', campaign: 'camp-2'), H::tokens(3, 10));
        $h->ledger->recordIntent(H::intent(H::alert(), 'three', 'beta', 'camp-1'), H::tokens(1, 20));
        $h->acceptAll('default', 'w');
        $h->acceptAll('beta', 'w');

        expect($store->campaignFunnel('default', 'camp-1')->accepted)->toBe(2)
            ->and($store->campaignFunnel('default', 'camp-2')->accepted)->toBe(3)
            ->and($store->campaignFunnel('beta', 'camp-1')->accepted)->toBe(1);
    });

    it('rules: health counts each waiting and in-flight state of its scope only, and a row due this very millisecond is due', function () use ($make, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'beta'), [H::token(1)]);   // another scope, written first
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'acme'), H::tokens(5));
        $claimed = $h->ledger->claimDue('acme', 'w', 4);
        [$deferred, , $sending, $unknown] = $claimed;
        foreach ([$deferred, $sending, $unknown] as $c) {
            $h->ledger->markAttemptStarted($c->lease);
        }
        $h->ledger->record($deferred->lease, $h->deferred($deferred, 100), $h->budgetAfterOneAttempt());
        $h->ledger->record($unknown->lease, new SendResult($destinationOf($unknown), SendStatus::OutcomeUnknown, Reason::of(ReasonCode::TransportUnknown), 1, deliveryId: $unknown->id()), $h->budgetAfterOneAttempt());

        $acme = $h->ledger->health('acme');
        expect($acme->pending)->toBe(1)
            ->and($acme->deferred)->toBe(1)
            ->and($acme->inFlight)->toBe(2)
            ->and($acme->unknown)->toBe(1)
            ->and($acme->due)->toBe(1)
            ->and($acme->oldestDueAgeMs)->toBe(0)
            ->and($h->ledger->health('beta')->pending)->toBe(1)
            ->and($h->ledger->health('beta')->inFlight)->toBe(0)
            ->and($h->ledger->health('beta')->deferred)->toBe(0)
            ->and($h->ledger->health('beta')->unknown)->toBe(0)
            ->and($h->ledger->health('empty')->pending)->toBe(0)
            ->and($h->ledger->health()->pending)->toBe(2);

        // A newer due row does not make the backlog look younger.
        $h->clock->advance(30);
        $h->ledger->recordIntent(H::intent(H::alert(), 'later', 'acme'), [H::token(9)]);
        expect($h->ledger->health('acme')->due)->toBe(2)
            ->and($h->ledger->health('acme')->oldestDueAgeMs)->toBe(30_000);
    });

    it('rules: scopesWithWork lists, sorted and limited, the scopes with a due row or an expired lease', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'zeta'), [H::token(1)]);      // due this very millisecond
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'waiting'), [H::token(2)]);   // deferred into the future
        $c = $h->ledger->claimDue('waiting', 'w', 1)[0];
        $h->ledger->markAttemptStarted($c->lease);
        $h->ledger->record($c->lease, $h->deferred($c, 600), $h->budgetAfterOneAttempt());
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'leased'), [H::token(3)]);    // lease still running
        $h->ledger->claimDue('leased', 'w', 1);
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'sending'), [H::token(7)]);   // request in flight, lease running
        $s = $h->ledger->claimDue('sending', 'w', 1)[0];
        $h->ledger->markAttemptStarted($s->lease);
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'done'), [H::token(4)]);      // final only
        $h->acceptAll('done', 'w');
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', '42'), [H::token(5)]);
        $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'beta'), [H::token(6)]);

        expect($h->ledger->scopesWithWork())->toBe(['42', 'beta', 'zeta'])
            ->and($h->ledger->scopesWithWork(1))->toBe(['42'])
            ->and($h->ledger->scopesWithWork(2))->toBe(['42', 'beta']);

        $h->expireLeases();
        expect($h->ledger->scopesWithWork())->toBe(['42', 'beta', 'leased', 'sending', 'zeta']);
        $h->clock->advance(600);   // the deferred row is due now
        expect($h->ledger->scopesWithWork())->toBe(['42', 'beta', 'leased', 'sending', 'waiting', 'zeta']);
    });

    it('rules: scopesWithWork lists 100 scopes by default', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        for ($i = 0; $i < 101; $i++) {
            $h->ledger->recordIntent(H::intent(H::alert(), 'k', sprintf('tenant-%03d', $i)), [H::token($i)]);
        }

        $scopes = $h->ledger->scopesWithWork();
        expect($scopes)->toHaveCount(100)
            ->and($scopes[0])->toBe('tenant-000')
            ->and($scopes[99])->toBe('tenant-099');
    });

    it('rules: prune keeps a final row until it is strictly older than the retention', function () use ($make, $settings): void {
        /** @var H $h */
        $h = $make()->withSettings($settings(dedupe: 0));
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
        $h->acceptAll('default', 'w');

        $h->clock->advance(60);
        expect($h->ledger->prune(60)->deliveries)->toBe(0);
        $h->advanceMs(1);
        $pruned = $h->ledger->prune(60);
        expect($pruned->deliveries)->toBe(1)
            ->and($pruned->effects)->toBe(1)
            ->and($h->ledger->effect('default', $effect->id))->toBeNull();
    });

    it('rules: prune waits for the effect\'s window: kept one millisecond before its end, pruned at its end', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $old = $h->ledger->recordIntent(H::intent(H::alert(), 'old'), [H::token(1)])->effect;
        $h->acceptAll('default', 'w');

        $h->clock->advance(120);   // older than the retention, the window (3600 s) still open
        expect($h->ledger->prune(60)->deliveries)->toBe(0);
        $h->clock->advance(3600 - 120);
        $h->advanceMs(-1);
        expect($h->ledger->prune(60)->deliveries)->toBe(0)
            ->and($h->ledger->prune(60)->effects)->toBe(0);

        $live = $h->ledger->recordIntent(H::intent(H::alert(), 'live'), [H::token(2)])->effect;
        $h->advanceMs(1);
        $pruned = $h->ledger->prune(60);
        expect($pruned->deliveries)->toBe(1)
            ->and($pruned->effects)->toBe(1)
            ->and($h->ledger->effect('default', $old->id))->toBeNull();

        // The keys of effects that were not pruned still deduplicate; the pruned key is simply free again.
        expect($h->ledger->recordIntent(H::intent(H::alert(), 'live'), [H::token(2)])->created)->toBeFalse();
        $reused = $h->ledger->recordIntent(H::intent(H::alert('new text'), 'old'), [H::token(1)]);
        expect($reused->created)->toBeTrue()
            ->and($reused->effect->id)->not->toBe($old->id)
            ->and($reused->previousEffectId)->toBeNull()
            ->and($live->id)->not->toBe('');
    });

    it('rules: prune of one scope leaves the other scopes alone', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $acme = $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'acme'), [H::token(1)])->effect;
        $beta = $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'beta'), [H::token(1)])->effect;
        $h->acceptAll('acme', 'w');
        $h->acceptAll('beta', 'w');
        $h->clock->advance(3700);

        $pruned = $h->ledger->prune(60, 'acme');
        expect($pruned->deliveries)->toBe(1)
            ->and($pruned->effects)->toBe(1)
            ->and($h->ledger->effect('acme', $acme->id))->toBeNull()
            ->and($h->ledger->effect('beta', $beta->id))->not->toBeNull()
            ->and($h->ledger->counts('beta', $beta->id)->of(DeliveryState::Accepted))->toBe(1);
    });

    it('rules: a batch of starts follows each lease on its own, whatever owner, version and scope the others have', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $twice = $h->ledger->recordIntent(H::intent(H::alert(), 'twice'), [H::token(1)])->effect;
        $h->ledger->claimDue('default', 'w', 1, $twice->id);
        $h->expireLeases();
        $reclaimed = $h->ledger->claimDue('default', 'w', 1, $twice->id)[0];            // same owner, version 3
        $fresh = $h->ledger->recordIntent(H::intent(H::alert(), 'fresh'), [H::token(2)])->effect;
        $plain = $h->ledger->claimDue('default', 'w', 1, $fresh->id)[0];                // version 1
        $others = $h->ledger->recordIntent(H::intent(H::alert(), 'others'), [H::token(3)])->effect;
        $other = $h->ledger->claimDue('default', 'other-worker', 1, $others->id)[0];    // another owner, version 1
        $beta = $h->ledger->recordIntent(H::intent(H::alert(), 'beta', 'beta'), [H::token(4)])->effect;
        $elsewhere = $h->ledger->claimDue('beta', 'w', 1, $beta->id)[0];                // another scope, version 1
        $gone = $h->ledger->recordIntent(H::intent(H::alert(), 'gone'), [H::token(5)])->effect;
        $lost = $h->ledger->claimDue('default', 'w', 1, $gone->id)[0];                  // same owner and version, then cancelled
        $h->ledger->cancelEffect('default', $gone->id);

        expect($reclaimed->lease->version)->not->toBe($plain->lease->version);
        $h->clock->advance(1);
        $at = LedgerTime::ms($h->clock->now());
        $started = $h->ledger->markAttemptsStarted([$reclaimed->lease, $plain->lease, $other->lease, $elsewhere->lease, $lost->lease]);
        $expected = [$reclaimed->id(), $plain->id(), $other->id(), $elsewhere->id()];
        sort($started);
        sort($expected);
        expect($started)->toBe($expected)
            ->and($h->ledger->delivery('default', $lost->id())?->state)->toBe(DeliveryState::Cancelled)
            ->and(LedgerTime::ms($h->ledger->delivery('default', $plain->id())?->updatedAt ?? $h->clock->now()->modify('-1 day')))->toBe($at)
            ->and(LedgerTime::ms($h->ledger->delivery('default', $plain->id())?->attemptStartedAt ?? $h->clock->now()->modify('-1 day')))->toBe($at);
    });

    it('rules: a batch of records answers every record, whatever the ones before it were', function () use ($make, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(3));
        [$foreign, $final, $valid] = $h->ledger->claimDue('default', 'w', 3);
        foreach ([$foreign, $final, $valid] as $c) {
            $h->ledger->markAttemptStarted($c->lease);
        }
        $h->ledger->record($final->lease, $h->accepted($final), $h->budgetAfterOneAttempt());
        $wrongScope = new Lease($foreign->id(), 'beta', $foreign->lease->owner, $foreign->lease->version, $foreign->lease->expiresAt);
        // A result the sender built without the ledger's delivery id.
        $plain = new SendResult($destinationOf($valid), SendStatus::Accepted, Reason::none(), 1, 'apns-v', 200);

        $out = $h->ledger->recordMany([
            [$wrongScope, $h->accepted($foreign), null],
            [$final->lease, $h->accepted($final), null],
            [$valid->lease, $plain, $h->budgetAfterOneAttempt()],
        ]);
        expect($out[$foreign->id()])->toBe(Recording::NotFound)
            ->and($out[$final->id()])->toBe(Recording::IgnoredFinal)
            ->and($out[$valid->id()])->toBe(Recording::Recorded)
            ->and($h->ledger->delivery('default', $foreign->id())?->state)->toBe(DeliveryState::Sending)
            ->and($h->ledger->delivery('default', $valid->id())?->providerMessageId)->toBe('apns-v');
    });

    it('rules: a claim takes the oldest due rows across pending and deferred, and never more than its limit', function () use ($make, $destinationOf): void {
        /** @var H $h */
        $h = $make();
        $early = $h->ledger->recordIntent(H::intent(H::alert(), 'early'), H::tokens(2))->effect;
        foreach ($h->ledger->claimDue('default', 'w', 2) as $c) {
            $h->ledger->markAttemptStarted($c->lease);
            $h->ledger->record($c->lease, new SendResult($destinationOf($c), SendStatus::Deferred, Reason::of(ReasonCode::ProviderUnavailable), 1,
                nextAttemptAt: $h->clock->now()->modify('+5 seconds'), deliveryId: $c->id()), $h->budgetAfterOneAttempt());
        }
        $h->clock->advance(10);
        $h->ledger->recordIntent(H::intent(H::alert(), 'late'), H::tokens(2, 10));   // pending, due since now

        $deferred = array_map(static fn (array $r): string => (string) $r['id'], array_filter($h->rows()['deliveries'], static fn (array $r): bool => $r['effect_id'] === $early->id));
        sort($deferred, SORT_STRING);
        expect($h->claimIds('default', 'w', 2))->toBe(array_values($deferred));
    });

    it('rules: prune, results and claims walk past one chunk of rows', function () use ($make, $settings): void {
        /** @var H $h */
        $h = $make()->withSettings($settings(dedupe: 0));   // chunk size 100
        $big = $h->ledger->recordIntent(H::intent(H::alert(), 'big'), H::tokens(150))->effect;
        for ($i = 0; $i < 101; $i++) {
            $h->ledger->recordIntent(H::intent(H::alert(), "small-{$i}"), [H::token(1000 + $i)]);
        }
        expect(iterator_to_array($h->ledger->results('default', $big->id), false))->toHaveCount(150)
            ->and($h->acceptAll('default', 'w'))->toBe(251);

        $h->clock->advance(61);
        $pruned = $h->ledger->prune(60);
        expect($pruned->deliveries)->toBe(251)
            ->and($pruned->effects)->toBe(102);
    });

    /*
     * Four rules on which the two implementations used to differ (found while killing mutants, 2026-11-21): the
     * in-memory ledger was brought in line with the database ledger, and the contract now holds for both.
     */
    it('rules: a claim with a limit of 0 or less is a no-op: it sweeps no expired lease either', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(3))->effect;
        $h->ledger->claimDue('default', 'worker-a', 3);
        $h->expireLeases();

        expect($h->ledger->claimDue('default', 'worker-b', 0))->toBe([])
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Leased))->toBe(3);
    });

    it('rules: a second cancel of a row already flagged in flight changes nothing and counts nothing', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;
        $claimed = $h->ledger->claimDue('default', 'w', 1)[0];
        $h->ledger->markAttemptStarted($claimed->lease);

        expect($h->ledger->cancelEffect('default', $effect->id))->toBe(1)
            ->and($h->ledger->cancelEffect('default', $effect->id))->toBe(0);
    });

    it('rules: scopesWithWork sorts scope names as strings, digits included', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        foreach (['9', '10', 'acme'] as $scope) {
            $h->ledger->recordIntent(H::intent(H::alert(), 'k', $scope), [H::token(1)]);
        }

        expect($h->ledger->scopesWithWork())->toBe(['10', '9', 'acme']);
    });

    it('rules: a reclaim with a limit takes the oldest expired leases first', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $first = $h->ledger->recordIntent(H::intent(H::alert(), 'first'), [H::token(1)])->effect;
        $second = $h->ledger->recordIntent(H::intent(H::alert(), 'second'), [H::token(2)])->effect;
        $older = $h->ledger->claimDue('default', 'w', 1, $second->id)[0];
        $h->clock->advance(10);
        $newer = $h->ledger->claimDue('default', 'w', 1, $first->id)[0];
        $h->clock->advance(80);

        expect($h->ledger->reclaimExpiredLeases('default', 1)->requeued)->toBe(1)
            ->and($h->ledger->delivery('default', $older->id())?->state)->toBe(DeliveryState::Pending)
            ->and($h->ledger->delivery('default', $newer->id())?->state)->toBe(DeliveryState::Leased);
    });
}
