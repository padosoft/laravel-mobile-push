<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\EffectOrigin;
use Padosoft\MobilePush\Core\Ledger\RecordedDestination;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

/*
 * The ledger contract: the same stories, run against every implementation (in memory and database).
 * $make is called inside each test and returns a fresh harness.
 */
function ledgerContract(Closure $make): void
{
    it('the worker died before sending: the lease expires, the row is due again, attempts unchanged', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;

        $first = $h->ledger->claimDue('default', 'worker-a', 10);
        expect($first)->toHaveCount(1)
            ->and($h->ledger->claimDue('default', 'worker-b', 10))->toBe([]);

        $h->expireLeases();
        $again = $h->ledger->claimDue('default', 'worker-b', 10);

        expect($again)->toHaveCount(1)
            ->and($again[0]->id())->toBe($first[0]->id())
            ->and($again[0]->delivery->attempts)->toBe(0)
            ->and($again[0]->budget())->toBeNull()
            // Worker A's lease is gone: it may not start, and its late record is ignored.
            ->and($h->ledger->markAttemptStarted($first[0]->lease))->toBeFalse()
            ->and($h->ledger->record($first[0]->lease, $h->accepted($first[0])))->toBe(Recording::IgnoredStaleLease)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Leased))->toBe(1);
    });

    it('the worker died after APNs answered but before saving, on an alert: OutcomeUnknown, never claimable, not failed', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)])->effect;

        $claimed = $h->ledger->claimDue('default', 'worker-a', 10)[0];
        expect($h->ledger->markAttemptStarted($claimed->lease))->toBeTrue();
        // ... APNs answers 200, the process is killed before record().
        $h->expireLeases();

        expect($h->ledger->claimDue('default', 'worker-b', 10))->toBe([]);
        $h->clock->advance(3600);
        expect($h->ledger->claimDue('default', 'worker-b', 10))->toBe([]);

        $row = $h->ledger->delivery('default', $claimed->id());
        $counts = $h->ledger->counts('default', $effect->id);
        $result = iterator_to_array($h->ledger->results('default', $effect->id))[0];

        expect($row->state)->toBe(DeliveryState::OutcomeUnknown)
            ->and($row->reason->code)->toBe(ReasonCode::TransportUnknown)
            ->and($row->budget?->unknownOutcomeSeen)->toBeTrue()
            ->and($row->attempts)->toBe(1)
            ->and($counts->of(DeliveryState::Failed))->toBe(0)
            ->and($counts->notAccepted())->toBe(0)
            ->and($result->status)->toBe(SendStatus::OutcomeUnknown)
            ->and($result->status->allowsFallback())->toBeFalse();
    });

    it('the same crash on a replaceable Live Activity update: due again with the budget marked unknown', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::liveUpdate()), [H::token(1)]);

        $claimed = $h->ledger->claimDue('default', 'worker-a', 10)[0];
        $h->ledger->markAttemptStarted($claimed->lease);
        $h->expireLeases();

        $again = $h->ledger->claimDue('default', 'worker-b', 10);
        expect($again)->toHaveCount(1)
            ->and($again[0]->delivery->attempts)->toBe(1)
            ->and($again[0]->budget()?->unknownOutcomeSeen)->toBeTrue()
            ->and($again[0]->budget()?->attempts)->toBe(1);

        // The retry succeeds: the row is accepted.
        $h->ledger->markAttemptStarted($again[0]->lease);
        $budget = $again[0]->budget()?->withAttempt();
        expect($h->ledger->record($again[0]->lease, $h->accepted($again[0]), $budget))->toBe(Recording::Recorded)
            ->and($h->ledger->delivery('default', $again[0]->id())->state)->toBe(DeliveryState::Accepted)
            ->and($h->ledger->delivery('default', $again[0]->id())->attempts)->toBe(2);
    });

    it('a crash in the middle of a chunk: 4 of 10 accepted stay accepted, the other 6 follow the lease rules', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(10))->effect;

        $chunk = $h->ledger->claimDue('default', 'worker-a', 10);
        expect($chunk)->toHaveCount(10);
        $accepted = [];
        foreach (array_slice($chunk, 0, 4) as $c) {
            $h->ledger->markAttemptStarted($c->lease);
            $h->ledger->record($c->lease, $h->accepted($c), $h->budgetAfterOneAttempt());
            $accepted[] = $c->id();
        }
        // Two more requests had left when the worker died; four were still queued in memory.
        $started = [];
        foreach (array_slice($chunk, 4, 2) as $c) {
            $h->ledger->markAttemptStarted($c->lease);
            $started[] = $c->id();
        }
        $notStarted = array_map(static fn ($c): string => $c->id(), array_slice($chunk, 6));

        $h->expireLeases();
        $again = $h->claimIds('default', 'worker-b', 100);

        sort($again);
        sort($notStarted);
        expect($again)->toBe($notStarted)
            ->and(array_intersect($again, $accepted))->toBe([])
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Accepted))->toBe(4)
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::OutcomeUnknown))->toBe(2)
            ->and($h->ledger->counts('default', $effect->id)->total())->toBe(10);
        foreach ($started as $id) {
            expect($h->ledger->delivery('default', $id)->state)->toBe(DeliveryState::OutcomeUnknown);
        }
        foreach ($accepted as $id) {
            expect($h->ledger->delivery('default', $id)->providerMessageId)->toBe('apns-id-1');
        }
    });

    it('two workers, one queue: interleaved claims over 1,000 deliveries hand out every row exactly once', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), H::tokens(1000));

        $seen = [];
        $workers = ['worker-a' => 37, 'worker-b' => 53];
        do {
            $got = 0;
            foreach ($workers as $worker => $n) {
                foreach ($h->claimIds('default', $worker, $n) as $id) {
                    $seen[] = $id;
                    $got++;
                }
            }
        } while ($got > 0);

        expect($seen)->toHaveCount(1000)
            ->and(array_unique($seen))->toHaveCount(1000);
    });

    it('a late answer from a stale lease is ignored: the row keeps worker B\'s accepted data', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::liveUpdate()), [H::token(1)]);

        $a = $h->ledger->claimDue('default', 'worker-a', 1)[0];
        $h->ledger->markAttemptStarted($a->lease);
        $h->expireLeases();

        $b = $h->ledger->claimDue('default', 'worker-b', 1)[0];
        $h->ledger->markAttemptStarted($b->lease);
        expect($h->ledger->record($b->lease, $h->accepted($b, 'apns-id-B'), $b->budget()?->withAttempt()))->toBe(Recording::Recorded);

        $late = $h->deferred($a, 60);
        expect($h->ledger->record($a->lease, $late))->toBe(Recording::IgnoredFinal)
            ->and($h->ledger->record($b->lease, $h->accepted($b, 'apns-id-dup')))->toBe(Recording::IgnoredFinal);

        $row = $h->ledger->delivery('default', $b->id());
        expect($row->state)->toBe(DeliveryState::Accepted)
            ->and($row->providerMessageId)->toBe('apns-id-B')
            ->and($row->leaseOwner)->toBeNull();
    });

    it('same key and payload twice: one effect, no duplicate rows, a new destination appears once', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $first = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2), H::token(1)]);
        $second = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2), H::token(3)]);
        $third = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(3)]);

        expect($first->created)->toBeTrue()
            ->and($first->added)->toBe(2)
            ->and($first->existing)->toBe(1)
            ->and($second->created)->toBeFalse()
            ->and($second->effect->id)->toBe($first->effect->id)
            ->and($second->added)->toBe(1)
            ->and($third->added)->toBe(0)
            ->and($h->rows()['effects'])->toHaveCount(1)
            ->and($h->rows()['deliveries'])->toHaveCount(3);

        expect(fn () => $h->ledger->recordIntent(H::intent(H::alert('Your order was cancelled')), [H::token(9)]))
            ->toThrow(IdempotencyConflictException::class);
        expect($h->rows()['deliveries'])->toHaveCount(3);
    });

    it('keys cover topics too, and the dedupe window ends: a later effect may reuse the key', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $a = $h->ledger->recordIntent(H::intent(H::alert(), 'daily-news'), [H::topic('news'), H::topic('/topics/news')]);
        expect($a->added)->toBe(1)->and($a->existing)->toBe(1);

        $h->clock->advance($h->settings->dedupeSeconds + 1);
        $b = $h->ledger->recordIntent(H::intent(H::alert('different'), 'daily-news'), [H::topic('news')]);

        expect($b->created)->toBeTrue()
            ->and($b->effect->id)->not->toBe($a->effect->id)
            ->and($b->added)->toBe(1);
    });

    it('the alert expired while deferred: never handed to a worker, it ends Expired', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $expiry = $h->clock->now()->modify('+10 minutes');
        $effect = $h->ledger->recordIntent(H::intent(H::alert(), expiresAt: $expiry), [H::token(1)])->effect;

        $c = $h->ledger->claimDue('default', 'worker-a', 1)[0];
        $h->ledger->markAttemptStarted($c->lease);
        expect($h->ledger->record($c->lease, $h->deferred($c, 900), $h->budgetAfterOneAttempt()))->toBe(Recording::Recorded);

        $row = $h->ledger->delivery('default', $c->id());
        expect($row->state)->toBe(DeliveryState::Deferred)
            ->and($row->leaseOwner)->toBeNull()
            ->and($row->nextAttemptAt?->getTimestamp())->toBe($h->clock->now()->getTimestamp() + 900);

        $h->clock->advance(901);
        expect($h->ledger->claimDue('default', 'worker-b', 10))->toBe([])
            ->and($h->ledger->delivery('default', $c->id())->state)->toBe(DeliveryState::Expired)
            ->and(iterator_to_array($h->ledger->results('default', $effect->id))[0]->status)->toBe(SendStatus::Expired);
    });

    it('a deferred row is not due before its time, and the lease is free meanwhile', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);
        $c = $h->ledger->claimDue('default', 'worker-a', 1)[0];
        $h->ledger->markAttemptStarted($c->lease);
        $h->ledger->record($c->lease, $h->deferred($c, 120), $h->budgetAfterOneAttempt());

        expect($h->ledger->claimDue('default', 'worker-b', 1))->toBe([]);
        $h->clock->advance(121);
        $again = $h->ledger->claimDue('default', 'worker-b', 1);
        expect($again)->toHaveCount(1)
            ->and($again[0]->budget()?->attempts)->toBe(1)
            ->and($again[0]->delivery->reason->code)->toBe(ReasonCode::ProviderUnavailable);
    });

    it('two tenants, same effect key and same devices: claims, reads, cancels and forgets never cross', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $acme = $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'acme'), [H::device(7), H::device(8)])->effect;
        $beta = $h->ledger->recordIntent(H::intent(H::alert('other'), 'k', 'beta'), [H::device(7), H::device(8)])->effect;

        expect($acme->id)->not->toBe($beta->id);

        $acmeIds = $h->claimIds('acme', 'w', 10);
        expect($acmeIds)->toHaveCount(2)
            ->and($h->ledger->delivery('beta', $acmeIds[0]))->toBeNull()
            ->and($h->ledger->effect('beta', $acme->id))->toBeNull()
            ->and($h->ledger->message('beta', $acme->id))->toBeNull()
            ->and($h->ledger->counts('beta', $acme->id)->total())->toBe(0)
            ->and($h->ledger->cancelEffect('beta', $acme->id))->toBe(0)
            ->and($h->ledger->forgetOwner('beta', 'App\\Models\\User', '1'))->toBe(2);

        // acme is untouched by beta's forget; beta's own rows are cancelled.
        expect($h->ledger->counts('acme', $acme->id)->of(DeliveryState::Leased))->toBe(2)
            ->and($h->ledger->counts('beta', $beta->id)->of(DeliveryState::Cancelled))->toBe(2)
            ->and($h->ledger->delivery('acme', $acmeIds[0])->ownerId)->toBe('1')
            ->and($h->claimIds('beta', 'w', 10))->toBe([]);
    });

    it('forget me with jobs pending: cancelled, unclaimable, not re-created, no personal data left', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $intent = new EffectIntent(H::binding(), H::alert('Hi Alice'), 'welcome', origin: new EffectOrigin('App\\Models\\User', '1', 'App\\Notifications\\Welcome'));
        $effect = $h->ledger->recordIntent($intent, [H::device(1), H::device(2), H::fcm('alice-raw')])->effect;
        $other = $h->ledger->recordIntent(H::intent(H::alert(), 'bob'), [H::device(3, ownerId: '2')])->effect;

        expect($h->ledger->forgetOwner('default', 'App\\Models\\User', '1'))->toBe(3);

        // The queued job runs again and re-submits the same effect: nothing comes back.
        $replay = $h->ledger->recordIntent($intent, [H::device(1), H::device(2), H::fcm('alice-raw')]);
        expect($replay->added)->toBe(0)
            ->and($h->ledger->claimDue('default', 'w', 10, $effect->id))->toBe([])
            ->and($h->ledger->counts('default', $effect->id)->of(DeliveryState::Cancelled))->toBe(3)
            ->and($h->ledger->message('default', $effect->id))->toBeNull();

        foreach ($h->rows()['deliveries'] as $row) {
            if ($row['effect_id'] === $effect->id) {
                expect($row['destination'])->toBeNull()
                    ->and($row['owner_id'])->toBeNull()
                    ->and($row['owner_type'])->toBeNull()
                    ->and($row['device_id'])->toBeNull();
            }
        }
        foreach ($h->rows()['effects'] as $row) {
            if ($row['id'] === $effect->id) {
                expect($row['message'])->toBeNull()->and($row['origin_id'])->toBeNull();
            }
        }
        // Bob is untouched.
        expect($h->claimIds('default', 'w', 10, $other->id))->toHaveCount(1);
    });

    it('a cancel that meets an in-flight request keeps its real outcome and prevents the next attempt', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert(), campaign: 'black-friday'), H::tokens(3))->effect;
        [$sending, $leased] = $h->ledger->claimDue('default', 'w', 2);
        $h->ledger->markAttemptStarted($sending->lease);

        expect($h->ledger->cancelCampaign('default', 'black-friday'))->toBe(3)
            ->and($h->ledger->markAttemptStarted($leased->lease))->toBeFalse()
            ->and($h->ledger->record($sending->lease, $h->deferred($sending, 60), $h->budgetAfterOneAttempt()))->toBe(Recording::Recorded)
            ->and($h->ledger->delivery('default', $sending->id())->state)->toBe(DeliveryState::Cancelled)
            ->and($h->ledger->campaignCounts('default', 'black-friday')->of(DeliveryState::Cancelled))->toBe(3)
            ->and($h->claimIds('default', 'w', 10))->toBe([]);

        $reasons = array_map(static fn ($r) => $r->reason->code, iterator_to_array($h->ledger->results('default', $effect->id)));
        expect(array_unique($reasons, SORT_REGULAR))->toBe([ReasonCode::Cancelled]);
    });

    it('streams the outcome of an effect into a SendReport and reports health', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::device(1), H::fcm('raw'), H::topic('news')])->effect;
        $h->clock->advance(30);
        expect($h->ledger->health('default')->due)->toBe(3)
            ->and($h->ledger->health('default')->oldestDueAgeMs)->toBe(30_000)
            ->and($h->ledger->scopesWithWork())->toBe(['default']);

        $h->acceptAll('default', 'w');
        $report = new SendReport($h->ledger->results('default', $effect->id));
        $results = iterator_to_array($report->results());

        expect($results)->toHaveCount(3)
            ->and(array_map(static fn ($r) => $r->status, $results))->each->toBe(SendStatus::Accepted)
            ->and(array_filter($results, static fn ($r) => $r->destination instanceof RecordedDestination))->toHaveCount(1)
            ->and(array_map(static fn ($r) => $r->deliveryId, $results))->each->not->toBeNull()
            ->and($h->ledger->health('default')->due)->toBe(0)
            ->and($h->ledger->health()->oldestDueAgeMs)->toBeNull();

        $raw = array_values(array_filter($results, static fn ($r) => ! $r->destination instanceof RecordedDestination && $r->destination->kind()->value === 'fcm_token'));
        expect($raw[0]->destination->value())->toBe(H::fcm('raw')->value());
    });

    it('prunes terminal rows after retention and the dedupe window, keeping live ones', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $done = $h->ledger->recordIntent(H::intent(H::alert(), 'done'), H::tokens(3))->effect;
        $h->acceptAll('default', 'w');
        $live = $h->ledger->recordIntent(H::intent(H::alert(), 'live'), H::tokens(2, 100))->effect;

        expect($h->ledger->prune(60)->deliveries)->toBe(0); // dedupe window still open

        $h->clock->advance($h->settings->dedupeSeconds + 120);
        $pruned = $h->ledger->prune(60);

        expect($pruned->deliveries)->toBe(3)
            ->and($pruned->effects)->toBe(1)
            ->and($h->ledger->effect('default', $done->id))->toBeNull()
            ->and($h->ledger->counts('default', $live->id)->of(DeliveryState::Pending))->toBe(2);
    });

    it('nothing readable at rest: no column holds the token or the message text in clear', function () use ($make): void {
        /** @var H $h */
        $h = $make();
        $token = H::token('feedface');
        $fcm = H::fcm('MARKERTOKEN');
        $message = H::alert('SECRET-MARKER-BODY')->title('TITLE-MARKER');
        $effect = $h->ledger->recordIntent(H::intent($message), [$token, $fcm, H::device(5)])->effect;
        $h->acceptAll('default', 'w');

        $haystack = '';
        foreach ($h->rows() as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $column => $value) {
                    $haystack .= "\n{$column}=".(is_scalar($value) ? (string) $value : '');
                }
            }
        }

        foreach ([$token->value(), $fcm->value(), 'SECRET-MARKER-BODY', 'TITLE-MARKER', 'MARKERTOKEN'] as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse("'{$needle}' readable at rest");
        }
        // ... and yet the relay can read the message back.
        expect($h->ledger->message('default', $effect->id)?->body)->toBe('SECRET-MARKER-BODY');
    });
}
