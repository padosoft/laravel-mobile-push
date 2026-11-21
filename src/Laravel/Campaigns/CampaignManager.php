<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\Ulid;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\Audience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceContext;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCancelled;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignPaused;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignResumed;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignStarted;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;

/**
 * Campaigns: create, control, observe. Every method is scoped: a campaign id from another scope is not found.
 *
 * Controls are explicit state transitions (CampaignStatus::after()), applied with compare-and-set on the row version
 * and idempotent: pausing a paused campaign or cancelling a cancelled one changes nothing and raises no event.
 *  - pause: the planner writes no further slice; deliveries already in the ledger keep being sent.
 *  - resume: planning continues from the stored cursor; nothing is recomputed from zero.
 *  - cancel: nothing further is planned; the ledger cancels every delivery not in flight. What was accepted (or is
 *    in flight) cannot be retracted.
 *
 * Numbers always come from the ledger (progress(), reconcile()).
 */
final readonly class CampaignManager
{
    private const SWEEP_LIMIT = 200;

    public function __construct(
        private CampaignStore $store,
        private Ledger $ledger,
        private PushDispatcher $dispatcher,
        private CampaignPlanner $planner,
        private CampaignCompletion $completion,
        private CampaignJobs $jobs,
        private Dispatcher $events,
        private CampaignSettings $settings,
    ) {}

    /** The fluent builder (what MobilePush::campaign($message) returns). */
    public function campaign(Message $message): CampaignBuilder
    {
        return new CampaignBuilder($this, $message, $this->settings);
    }

    public function find(string $scope, string $id): ?Campaign
    {
        return $this->store->find($scope, $id);
    }

    /**
     * Create the campaign row and its ledger effect (the encrypted message, no delivery yet). Status `draft`.
     *
     * @internal use CampaignBuilder::draft() / dispatch()
     */
    public function create(
        BindingRef $binding,
        Message $message,
        Audience $audience,
        string $name,
        ?\DateTimeImmutable $expiresAt,
        ?int $ratePerMinute,
        ?string $queueConnection,
        ?string $queue,
    ): Campaign {
        $this->settings->assertUsable();
        Pacing::assertRate($ratePerMinute);

        $scope = Scope::normalize($binding->scope);
        $now = $this->store->nowMs();
        $id = Ulid::generate(Millis::date($now) ?? new \DateTimeImmutable);

        $intent = $this->ledger->recordIntent(new EffectIntent(
            $binding,
            $message,
            'campaign:'.$id,
            expiresAt: $expiresAt,
            trafficClass: TrafficClass::Bulk,
            campaignId: $id,
            dedupeSeconds: $this->settings->retentionDays * 86_400,
        ), []);

        [$type, $sealed] = $this->store->sealAudience($audience, $scope, $id);
        $title = $message instanceof PushMessage && $message->title !== null ? mb_substr($message->title, 0, 191) : null;

        $this->store->insert([
            'id' => $id,
            'scope' => $scope,
            'name' => mb_substr($name, 0, 191),
            'binding' => json_encode($binding->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'binding_identity' => $binding->identity(),
            'effect_id' => $intent->effect->id,
            'audience_type' => $type,
            'audience' => $sealed,
            'reach' => $audience->reach()->value,
            'title' => $title,
            'traffic_class' => TrafficClass::Bulk->value,
            'expires_at' => $intent->effect->expiresAt === null ? null : Millis::of($intent->effect->expiresAt),
            'rate_per_minute' => $ratePerMinute,
            'slice_size' => $this->settings->slice,
            'queue_connection' => $queueConnection,
            'queue' => $queue,
            'status' => CampaignStatus::Draft->value,
            'planned' => 0,
            'slices' => 0,
            'invalid' => 0,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->store->get($scope, $id);
    }

    /**
     * draft → planning: fix the audience's upper bound now and queue the first slice once the campaigns connection
     * commits. Idempotent for a campaign that already started.
     */
    public function start(string $scope, string $id): Campaign
    {
        $this->settings->assertUsable();
        $campaign = $this->store->get($scope, $id);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $next = $this->next($campaign, CampaignControl::Start);
            if ($next === $campaign->status) {
                return $campaign;
            }
            $bound = $this->store->audience($campaign)->upperBound(new AudienceContext($campaign->scope, $campaign->binding, $campaign->id));
            $now = $this->store->nowMs();
            if ($this->store->cas($campaign, ['status' => $next->value, 'upper_bound' => $bound, 'cursor' => null, 'started_at' => $now])) {
                $started = $this->store->get($scope, $id);
                $this->afterCommit(function () use ($started): void {
                    $this->jobs->plan($started, 0);
                    $this->store->markSuccessor($started, 0);
                });
                $this->events->dispatch(new CampaignStarted($started->scope, $started->id, $started->name));

                return $started;
            }
            $campaign = $this->store->get($scope, $id);
        }

        throw new CampaignStateException('The campaign changed concurrently; try again.');
    }

    /** planning → paused: no further slice is written. Deliveries already written proceed. */
    public function pause(string $scope, string $id): Campaign
    {
        return $this->control($scope, $id, CampaignControl::Pause, function (Campaign $c): void {
            $this->events->dispatch(new CampaignPaused($c->scope, $c->id, $c->name));
        });
    }

    /** paused | failed → planning: continue from the stored cursor (never from zero). */
    public function resume(string $scope, string $id): Campaign
    {
        $campaign = $this->control($scope, $id, CampaignControl::Resume, function (Campaign $c): void {
            $this->events->dispatch(new CampaignResumed($c->scope, $c->id, $c->name));
        }, ['last_error' => null]);

        if ($campaign->status === CampaignStatus::Planning) {
            // Queued unconditionally (a resume also restarts a stalled planner): a duplicate finds its slice
            // written, or the lease held, and stops.
            $this->jobs->plan($campaign, $campaign->slices);
        }

        return $campaign;
    }

    /**
     * Cancel: nothing further is planned, and the ledger cancels every delivery that is not in flight. Already
     * accepted (or in flight) deliveries cannot be retracted; progress() reports them as they are.
     */
    public function cancel(string $scope, string $id): Campaign
    {
        $cancelled = 0;
        $campaign = $this->control($scope, $id, CampaignControl::Cancel, function (Campaign $c) use (&$cancelled): void {
            $cancelled = $this->ledger->cancelCampaign($c->scope, $c->id);
            $counts = $this->ledger->campaignCounts($c->scope, $c->id);
            $this->events->dispatch(new CampaignCancelled($c->scope, $c->id, $c->name, $cancelled, $counts->byState));
        }, fn (): array => ['finished_at' => $this->store->nowMs()]);

        if ($cancelled === 0) {
            // Idempotent replay (or rows a racing slice wrote after the first cancel): cancel again, harmless.
            $this->ledger->cancelCampaign($campaign->scope, $campaign->id);
        }

        return $campaign;
    }

    /** Live numbers from the ledger; also detects completion. */
    public function progress(string $scope, string $id): CampaignProgress
    {
        $campaign = $this->completion->check($this->store->get($scope, $id));
        $counts = $this->ledger->campaignCounts($campaign->scope, $campaign->id);
        $recipientsKnown = $campaign->reach->recipientsKnown();

        $notes = [];
        if (! $recipientsKnown) {
            $notes[] = 'Topics, conditions and broadcast channels count as one delivery per publication: the number of recipients is not known.';
        }
        if ($campaign->status === CampaignStatus::Cancelled) {
            $notes[] = sprintf('Cancelled: %d deliveries were cancelled before being sent; %d accepted and %d unknown outcomes happened before the cancel and cannot be retracted.', $counts->of(\Padosoft\MobilePush\Core\Ledger\DeliveryState::Cancelled), $counts->of(\Padosoft\MobilePush\Core\Ledger\DeliveryState::Accepted), $counts->of(\Padosoft\MobilePush\Core\Ledger\DeliveryState::OutcomeUnknown));
        }
        if (! $campaign->planningFinished() && ! $campaign->status->isFinal()) {
            $notes[] = 'Planning is not finished: totals cover the destinations planned so far, not the whole audience.';
        }
        if ($campaign->invalid > 0) {
            $notes[] = $campaign->invalid.' audience entries were not valid destinations and were skipped.';
        }

        $funnel = $recipientsKnown && $this->ledger instanceof ClientReceiptStore
            ? $this->ledger->campaignFunnel($campaign->scope, $campaign->id)
            : null;

        return new CampaignProgress($campaign, $counts, $recipientsKnown, $funnel, $this->estimate($campaign, $counts->terminal(), $counts->waiting() + $counts->inFlight()), $notes);
    }

    /** Verify the invariant planned = deliveries in the ledger (see ReconcileReport). Reports, never corrects. */
    public function reconcile(string $scope, string $id): ReconcileReport
    {
        $campaign = $this->store->get($scope, $id);
        $ledger = $this->ledger->campaignCounts($campaign->scope, $campaign->id)->total();
        $effect = $campaign->effectId === null ? 0 : $this->ledger->counts($campaign->scope, $campaign->effectId)->total();

        return new ReconcileReport($campaign->id, $campaign->planned, $ledger, $effect, $campaign->sliceOwner !== null);
    }

    /**
     * Maintenance, every scope (SweepCampaignsJob): restart planners that stalled (a worker died after its
     * checkpoint, or its lease expired) and detect completed campaigns.
     *
     * @return array{restarted: int, completed: int}
     */
    public function sweep(): array
    {
        $now = $this->store->nowMs();
        $stall = $now - $this->jobs->leaseSeconds() * 1000;
        $restarted = $completed = 0;

        foreach ($this->store->inStatus([CampaignStatus::Planning], self::SWEEP_LIMIT, $stall) as $campaign) {
            $leaseLive = $campaign->sliceOwner !== null && $campaign->sliceLeaseUntil !== null && Millis::of($campaign->sliceLeaseUntil) > $now;
            $slotLater = $campaign->nextSliceAt !== null && Millis::of($campaign->nextSliceAt) > $now;
            if (! $leaseLive && ! $slotLater) {
                $this->jobs->plan($campaign, $campaign->slices);
                $restarted++;
            }
        }
        foreach ($this->store->inStatus([CampaignStatus::Running], self::SWEEP_LIMIT) as $campaign) {
            if ($this->completion->check($campaign)->status === CampaignStatus::Completed) {
                $completed++;
            }
        }

        return ['restarted' => $restarted, 'completed' => $completed];
    }

    /** Delete finished campaign rows older than the retention (the ledger prunes its own rows). */
    public function prune(?int $retentionDays = null, ?string $scope = null): int
    {
        $days = $retentionDays ?? $this->settings->retentionDays;

        return $this->store->pruneFinished($this->store->nowMs() - $days * 86_400_000, 500, $scope);
    }

    public function planner(): CampaignPlanner
    {
        return $this->planner;
    }

    /** The dispatcher the builder resolves bindings with. @internal */
    public function dispatcher(): PushDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * @param  \Closure(Campaign): void  $then  runs once, only when the transition changed the status
     * @param  array<string, mixed>|\Closure(): array<string, mixed>  $extra
     */
    private function control(string $scope, string $id, CampaignControl $control, \Closure $then, array|\Closure $extra = []): Campaign
    {
        $campaign = $this->store->get($scope, $id);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $next = $this->next($campaign, $control);
            if ($next === $campaign->status) {
                return $campaign;
            }
            $changes = ['status' => $next->value] + ($extra instanceof \Closure ? $extra() : $extra);
            if ($this->store->cas($campaign, $changes)) {
                $changed = $this->store->get($scope, $id);
                $then($changed);

                return $changed;
            }
            $campaign = $this->store->get($scope, $id);
        }

        throw new CampaignStateException('The campaign changed concurrently; try again.');
    }

    private function next(Campaign $campaign, CampaignControl $control): CampaignStatus
    {
        return $campaign->status->after($control)
            ?? throw new CampaignStateException(sprintf('A %s campaign cannot be %s.', $campaign->status->value, match ($control) {
                CampaignControl::Start => 'started',
                CampaignControl::Pause => 'paused',
                CampaignControl::Resume => 'resumed',
                CampaignControl::Cancel => 'cancelled',
                default => $control->value,
            }));
    }

    private function afterCommit(\Closure $callback): void
    {
        $connection = $this->store->connection();
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);

            return;
        }
        $callback();
    }

    private function estimate(Campaign $campaign, int $done, int $remaining): ?ThroughputEstimate
    {
        if ($campaign->startedAt === null) {
            return null;
        }
        $now = $this->store->nowMs();
        $whole = $campaign->planningFinished() || $campaign->status->isFinal();
        $row = $this->store->find($campaign->scope, $campaign->id);
        if ($row === null) {
            return null;
        }
        [$sampleAt, $sampleDone, $stored] = $this->sampleOf($campaign);

        if ($sampleAt === null) {
            $this->store->sample($campaign, $now, $done, null);
        } elseif ($now - $sampleAt >= 60_000) {
            $rate = max(0, $done - $sampleDone) / (($now - $sampleAt) / 1000);
            $this->store->sample($campaign, $now, $done, $rate);

            return self::estimateFrom($rate, $remaining, ThroughputEstimate::BASIS_RECENT, intdiv($now - $sampleAt, 1000), $whole);
        } elseif ($stored !== null) {
            return self::estimateFrom($stored, $remaining, ThroughputEstimate::BASIS_RECENT, 60, $whole);
        }

        $elapsed = max(1, $now - Millis::of($campaign->startedAt));

        return self::estimateFrom($done / ($elapsed / 1000), $remaining, ThroughputEstimate::BASIS_SINCE_START, intdiv($elapsed, 1000), $whole);
    }

    /** @return array{0: int|null, 1: int, 2: float|null} */
    private function sampleOf(Campaign $campaign): array
    {
        return $this->store->sampleOf($campaign);
    }

    private static function estimateFrom(float $perSecond, int $remaining, string $basis, int $window, bool $whole): ThroughputEstimate
    {
        $seconds = match (true) {
            $remaining === 0 => 0,
            $perSecond > 0.0 => (int) ceil($remaining / $perSecond),
            default => null,
        };

        return new ThroughputEstimate(round($perSecond, 3), $seconds, $basis, $window, $whole);
    }
}
