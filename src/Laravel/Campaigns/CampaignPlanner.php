<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\IntentResult;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceContext;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceSlice;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignPlanned;
use Padosoft\MobilePush\Laravel\Queue\WorkerId;

/**
 * The slice planner: a thin, restartable loop on top of the ledger. It never sends and never holds more than one
 * slice in memory.
 *
 * One run (one PlanCampaignJob):
 *  1. the campaign must be `planning` (pause and cancel stop new acquisitions here) and this job's slice must be the
 *     next one (`expect` = slices written so far); a job whose slice is already written only makes sure the next
 *     job is queued;
 *  2. pacing: before `next_slice_at` the job re-queues itself for the slot;
 *  3. lease: the run takes the planner lease (compare-and-set). Finding an EXPIRED lease means an earlier run died
 *     somewhere inside its slice: this run is a recovery and recounts the checkpoint from the ledger;
 *  4. the slice is read from the cursor (never past the upper bound) and written with EffectStore::recordIntent()
 *     under the campaign's effect key: idempotent, one delivery per logical destination, rows already there are
 *     left alone (a rerun inserts nothing twice);
 *  5. only then the checkpoint advances (cursor, planned, slices) under the row version and the lease;
 *  6. a relay is queued for the effect, then the next planner job (with the pacing delay), or planning is marked
 *     finished.
 *
 * planned = unique destinations committed to the ledger: on the normal path the previous checkpoint plus the rows
 * this slice added; on a recovery the ledger's own count. A worker killed between 4 and 5 leaves the ledger ahead of
 * the checkpoint (reconcile() shows it as a slice in progress) until the next run recounts.
 */
final readonly class CampaignPlanner
{
    private const MAX_EARLY = 3;

    public function __construct(
        private CampaignStore $store,
        private Ledger $ledger,
        private CampaignJobs $jobs,
        private CampaignCompletion $completion,
        private Dispatcher $events,
        private CampaignSettings $settings,
    ) {}

    public function run(string $scope, string $id, int $expect = 0, int $early = 0): SliceOutcome
    {
        $campaign = $this->store->find($scope, $id);
        if ($campaign === null) {
            return SliceOutcome::Missing;
        }
        if (! $campaign->status->acquires()) {
            return SliceOutcome::Stopped;
        }
        if ($campaign->slices === $expect + 1) {
            $this->ensureNext($campaign);

            return SliceOutcome::Continued;
        }
        if ($campaign->slices !== $expect) {
            return SliceOutcome::Stale;
        }

        $now = $this->store->nowMs();
        $slot = $campaign->nextSliceAt === null ? null : Millis::of($campaign->nextSliceAt);
        if ($slot !== null && $slot > $now) {
            if ($early < self::MAX_EARLY) {
                $this->jobs->plan($campaign, $expect, Pacing::delaySeconds($slot, $now), $early + 1);
            }

            return SliceOutcome::Early;
        }

        $recovery = false;
        if ($campaign->sliceOwner !== null) {
            if ($campaign->sliceLeaseUntil !== null && Millis::of($campaign->sliceLeaseUntil) > $now) {
                return SliceOutcome::Busy;
            }
            $recovery = true;
        }

        $owner = WorkerId::generate();
        if (! $this->store->cas($campaign, ['slice_owner' => $owner, 'slice_lease_until' => $now + $this->jobs->leaseSeconds() * 1000])) {
            return SliceOutcome::Busy;
        }
        $campaign = $this->store->get($scope, $id);

        try {
            [$slice, $intent] = $this->writeSlice($campaign);
        } catch (CampaignException|IdempotencyConflictException|ValidationException $e) {
            // Permanent: retrying the same slice cannot succeed. Stop planning (resumable once fixed).
            $this->fail($campaign, $owner, $e);

            return SliceOutcome::Failed;
        }
        // Any other error (database down, worker killed) propagates with the lease held: the queue retries the job,
        // or the lease expires and the next run recovers.

        $planned = $recovery
            ? $this->ledger->campaignCounts($campaign->scope, $campaign->id)->total()
            : $campaign->planned + $intent->added;

        $advanced = $this->advance($campaign, $owner, $slice, $planned);
        if ($advanced === null) {
            return SliceOutcome::TakenOver;
        }

        if ($advanced->status === CampaignStatus::Cancelled) {
            // Cancelled while this slice was being written: cancel what it wrote too.
            $this->ledger->cancelCampaign($advanced->scope, $advanced->id);

            return SliceOutcome::Written;
        }

        if ($intent->added > 0 || $recovery) {
            // Deliveries already written always proceed, paused or not.
            $this->jobs->relay($advanced);
        }

        if ($advanced->planningFinished()) {
            $this->events->dispatch(new CampaignPlanned($advanced->scope, $advanced->id, $advanced->name, $advanced->planned, $advanced->slices, $advanced->invalid));
            $this->completion->check($advanced);
        } else {
            $this->ensureNext($advanced);
        }

        return SliceOutcome::Written;
    }

    /** Record the class of the error that made the queue give up on a planner job; the campaign stays resumable. */
    public function noteError(string $scope, string $id, ?\Throwable $e): void
    {
        $this->store->noteError($scope, $id, 'Planner job failed: '.($e === null ? 'unknown error' : $e::class));
    }

    /**
     * Queue the planner job for the next slice unless it is already queued (or the campaign stopped acquiring).
     * The job is queued BEFORE the marker is raised: a crash in between queues it once more, and the duplicate
     * finds its slice written and stops.
     */
    public function ensureNext(Campaign $campaign): void
    {
        if (! $campaign->status->acquires() || $campaign->planningFinished() || $this->store->successorQueued($campaign, $campaign->slices)) {
            return;
        }
        $slot = $campaign->nextSliceAt === null ? null : Millis::of($campaign->nextSliceAt);
        $this->jobs->plan($campaign, $campaign->slices, Pacing::delaySeconds($slot, $this->store->nowMs()));
        $this->store->markSuccessor($campaign, $campaign->slices);
    }

    /**
     * @return array{0: AudienceSlice, 1: IntentResult}
     *
     * @throws CampaignException|IdempotencyConflictException
     */
    private function writeSlice(Campaign $campaign): array
    {
        if ($campaign->effectId === null || $campaign->upperBound === null) {
            throw new CampaignException('The campaign has no ledger effect or no upper bound: it was never started.');
        }
        $effect = $this->ledger->effect($campaign->scope, $campaign->effectId)
            ?? throw new CampaignException('The ledger effect of the campaign no longer exists.');
        try {
            $message = $this->ledger->message($campaign->scope, $campaign->effectId);
        } catch (PrivacyException) {
            $message = null;
        }
        if ($message === null) {
            throw new CampaignException('The message snapshot of the campaign was erased or cannot be decrypted.');
        }

        $audience = $this->store->audience($campaign);
        $slice = $audience->slice(
            new AudienceContext($campaign->scope, $campaign->binding, $campaign->id),
            $campaign->cursor,
            $campaign->upperBound,
            Pacing::sliceSize($campaign->sliceSize, $campaign->ratePerMinute),
        );

        $intent = $this->ledger->recordIntent(new EffectIntent(
            $effect->binding,
            $message,
            $effect->effectKey,
            $effect->operation,
            $effect->replaceable,
            $effect->expiresAt,
            $effect->policy,
            TrafficClass::Bulk,
            null,
            $campaign->id,
            $this->settings->retentionDays * 86_400,
        ), $slice->destinations);

        if ($intent->effect->id !== $campaign->effectId) {
            // The key was released (dedupe window over) and a NEW effect took it: never plan into a second effect.
            $this->ledger->cancelEffect($campaign->scope, $intent->effect->id);

            throw new CampaignException('The campaign effect key is no longer owned by the campaign effect (its deduplication window ended).');
        }

        return [$slice, $intent];
    }

    /** Advance the checkpoint and free the lease. Null when another planner took the lease over meanwhile. */
    private function advance(Campaign $campaign, string $owner, AudienceSlice $slice, int $planned): ?Campaign
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $now = $this->store->nowMs();
            $changes = [
                'cursor' => $slice->nextCursor,
                'planned' => $planned,
                'slices' => $campaign->slices + 1,
                'invalid' => $campaign->invalid + $slice->invalid,
                'slice_owner' => null,
                'slice_lease_until' => null,
                'next_slice_at' => Pacing::nextSliceAt($now, count($slice->destinations) + $slice->invalid, $campaign->ratePerMinute),
                'last_error' => null,
            ];
            if ($slice->exhausted) {
                $changes['planned_at'] = $now;
                $changes['status'] = ($campaign->status->after(CampaignControl::FinishPlanning) ?? $campaign->status)->value;
            }

            if ($this->store->cas($campaign, $changes, $owner)) {
                return $this->store->get($campaign->scope, $campaign->id);
            }

            // A control (pause, cancel) changed the row meanwhile: the slice is in the ledger, record it anyway.
            $campaign = $this->store->latest($campaign->scope, $campaign->id);
            if ($campaign->sliceOwner !== $owner) {
                return null;
            }
        }

        throw new \RuntimeException('The campaign checkpoint could not be written after repeated conflicts.');
    }

    private function fail(Campaign $campaign, string $owner, \Throwable $e): void
    {
        // Only our own messages are stored: another exception's text could quote a value.
        $error = substr($e instanceof CampaignException ? $e::class.': '.$e->getMessage() : $e::class, 0, 255);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $campaign->status->after(CampaignControl::Fail) ?? $campaign->status;
            if ($this->store->cas($campaign, ['status' => $status->value, 'slice_owner' => null, 'slice_lease_until' => null, 'last_error' => $error], $owner)) {
                return;
            }
            $campaign = $this->store->latest($campaign->scope, $campaign->id);
            if ($campaign->sliceOwner !== $owner) {
                return;
            }
        }
    }
}
