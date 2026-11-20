<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelApiException;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelOutcomeUnknownException;
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\RowSnapshot;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionCommand;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionDecision;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionPayload;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionStore;

/**
 * Broadcast channels (iOS 18+): the only writer of the channels table, on top of ChannelClient.
 *
 *  - create(): the row is written (`creating`) BEFORE the API call, then becomes `active` with the id APNs returned.
 *    A lost create response leaves the row `unknown` and is never retried blindly: sync() lists the channels at
 *    APNs and adopts the orphan.
 *  - A local cap (below Apple's 10,000 per app and environment, counted across scopes because the app is shared)
 *    refuses a create before the network.
 *  - Channels outlive their subscribers: unreferenced() / cleanup() find and delete channels no open activity uses.
 *  - Publishing (through ActivityManager::broadcast()) is serialized per channel exactly like an activity, and an
 *    accepted publication is ONE publication: there is no per-device result, cap, receipt or fallback.
 *
 * @implements TransitionStore<BroadcastChannel>
 */
final readonly class ChannelRegistry implements TransitionStore
{
    /** A `creating` row older than this is treated by sync() like an `unknown` one (the process died mid-create). */
    private const STALE_CREATING_SECONDS = 120;

    public function __construct(
        private ChannelClientFactory $clients,
        private TypeRegistry $types,
        private LiveActivitiesConfig $config,
        private Rows $rows,
    ) {}

    /**
     * @throws ChannelLimitReachedException before the network when the local cap is reached
     * @throws ChannelApiException when APNs refused the creation (no channel exists; the row is marked deleted)
     */
    public function create(BindingRef $binding, StoragePolicy $policy = StoragePolicy::NoStorage, ?string $label = null, LiveActivityType|string|null $type = null): BroadcastChannel
    {
        self::assertApns($binding);
        $client = $this->clients->for($binding);

        $counted = $this->appQuery($binding)->whereIn('state', ChannelState::counted())->count();
        if ($counted >= $this->config->channelCap) {
            throw new ChannelLimitReachedException(sprintf(
                'The local cap of %d broadcast channels for %s (%s) is reached: delete unused channels (cleanup()) before creating more.',
                $this->config->channelCap, $binding->appId, $binding->environment->value,
            ));
        }

        $now = $this->rows->now();
        $row = BroadcastChannel::blank();
        $row->forceFill([
            'scope' => $binding->scope,
            'binding_name' => $binding->name,
            'binding_identity' => $binding->identity(),
            'app_id' => $binding->appId,
            'environment' => $binding->environment,
            'storage_policy' => $policy,
            'label' => $label,
            'type_key' => $type === null ? null : $this->types->keyOf($this->types->resolve($type)),
            'state' => ChannelState::Creating,
            'state_changed_at' => $now,
            'publications' => 0,
            'dispatch_seq' => 0,
            'applied_seq' => 0,
            'pending_attempts' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row->save();

        try {
            $channel = $client->create($policy);
        } catch (ChannelOutcomeUnknownException $e) {
            $this->force($row, ['state' => ChannelState::Unknown, 'create_request_id' => $e->requestId, 'state_changed_at' => $this->rows->now()]);

            return $row;
        } catch (ChannelApiException $e) {
            $this->force($row, ['state' => ChannelState::Deleted, 'closed_reason' => CloseReason::NeverCreated, 'state_changed_at' => $this->rows->now()]);

            throw $e;
        }

        $this->force($row, ['channel_id' => $channel->id, 'state' => ChannelState::Active, 'state_changed_at' => $this->rows->now()]);

        return $row;
    }

    /**
     * Delete the channel at APNs ("an irreversible action"), then locally.
     *
     * @throws ChannelApiException when APNs refused, or the outcome is unknown (the row stays `deleting`; sync() resolves it)
     */
    public function delete(BroadcastChannel $channel): BroadcastChannel
    {
        $row = $this->rows->fresh($channel);

        if ($row->state === ChannelState::Deleted) {
            return $row;
        }
        $channelId = $row->channel_id;
        if ($channelId === null) {
            throw new InvalidDestinationException('This channel has no APNs id (its creation outcome is unknown): run sync() first.');
        }

        $previous = $row->state;
        $client = $this->clients->for($this->bindingOf($row));
        $this->force($row, ['state' => ChannelState::Deleting, 'state_changed_at' => $this->rows->now()]);

        try {
            $client->delete($channelId);
        } catch (ChannelApiException $e) {
            if ($e->status === 404 || $e->reason->code === ReasonCode::ChannelUnknown) {
                $this->force($row, ['state' => ChannelState::Deleted, 'closed_reason' => CloseReason::DeletedRemotely, 'state_changed_at' => $this->rows->now()]);

                return $row;
            }
            if ($e->isRefusedBeforeNetwork() || $e->status > 0) {
                $this->force($row, ['state' => $previous, 'state_changed_at' => $this->rows->now()]);
            }

            throw $e;
        }

        $this->force($row, ['state' => ChannelState::Deleted, 'closed_reason' => CloseReason::Deleted, 'state_changed_at' => $this->rows->now()]);

        return $row;
    }

    /**
     * Reconcile APNs' list of channels for the binding's app and environment with the local rows, in both directions:
     *  - local `active` / `deleting` / `unknown` rows whose id APNs no longer lists become `deleted`;
     *  - an id APNs lists that no local row (of any scope) claims is an orphan. It is adopted by the local `unknown`
     *    row only when exactly one such row and exactly one orphan exist (otherwise it could be another tenant's or
     *    another create's channel); the others are reported, never guessed;
     *  - with no orphan at all, every `unknown` row was never created and becomes `deleted`.
     *
     * @throws ChannelApiException
     */
    public function sync(BindingRef $binding): SyncReport
    {
        self::assertApns($binding);
        $remote = $this->clients->for($binding)->all();
        $remoteSet = array_fill_keys($remote, true);
        $now = $this->rows->now();

        $deleted = [];
        $known = [];
        foreach ($this->appQuery($binding)->whereNotNull('channel_id')->get() as $row) {
            $id = (string) $row->channel_id;
            $known[$id] = true;

            if ($row->state !== ChannelState::Deleted && ! isset($remoteSet[$id])) {
                $this->force($row, ['state' => ChannelState::Deleted, 'closed_reason' => CloseReason::DeletedRemotely, 'state_changed_at' => $now]);
                $deleted[] = $id;
            }
        }

        $orphans = array_values(array_filter($remote, static fn (string $id): bool => ! isset($known[$id])));
        $staleCreating = $now->subSeconds(self::STALE_CREATING_SECONDS);
        $unresolved = $this->appQuery($binding)
            ->whereNull('channel_id')
            ->where(static function (Builder $q) use ($staleCreating): void {
                $q->where('state', ChannelState::Unknown->value)
                    ->orWhere(static fn (Builder $c) => $c->where('state', ChannelState::Creating->value)->where('created_at', '<', $c->getModel()->fromDateTime($staleCreating)));
            })
            ->orderBy('created_at')
            ->get();

        $adopted = [];
        $neverCreated = 0;

        if ($orphans === []) {
            foreach ($unresolved as $row) {
                $this->force($row, ['state' => ChannelState::Deleted, 'closed_reason' => CloseReason::NeverCreated, 'state_changed_at' => $now]);
                $neverCreated++;
            }
        } elseif (count($orphans) === 1 && count($unresolved) === 1) {
            $row = $unresolved->first();
            if ($row instanceof BroadcastChannel) {
                $this->force($row, ['channel_id' => $orphans[0], 'state' => ChannelState::Active, 'state_changed_at' => $now]);
                $adopted[] = $orphans[0];
                $orphans = [];
            }
        }

        return new SyncReport($adopted, $deleted, $neverCreated, $orphans, count($remote));
    }

    /** Attach an orphan found by sync() to a row by hand (the operator knows which create it was). */
    public function adopt(BroadcastChannel $unknown, string $channelId): BroadcastChannel
    {
        $row = $this->rows->fresh($unknown);
        if ($row->channel_id !== null || ! in_array($row->state, [ChannelState::Unknown, ChannelState::Creating], true)) {
            throw new InvalidDestinationException('Only a channel row whose creation outcome is unknown can adopt an id.');
        }

        $this->force($row, ['channel_id' => $channelId, 'state' => ChannelState::Active, 'state_changed_at' => $this->rows->now()]);

        return $row;
    }

    public function findByChannelId(BindingRef $binding, string $channelId): ?BroadcastChannel
    {
        $row = BroadcastChannel::blank()->newQuery()->forBinding($binding)->where('channel_id', $channelId)->first();

        return $row instanceof BroadcastChannel ? $row : null;
    }

    /**
     * Active channels of this scope + binding that no open activity references, idle for at least the configured
     * grace period (since their creation or last publication).
     *
     * @return list<BroadcastChannel>
     */
    public function unreferenced(BindingRef $binding, ?int $idleHours = null): array
    {
        $cutoff = $this->rows->now()->subHours($idleHours ?? $this->config->channelCleanupAfterHours);
        $model = BroadcastChannel::blank();
        $candidates = $model->newQuery()
            ->forBinding($binding)
            ->where('state', ChannelState::Active->value)
            ->whereNotNull('channel_id')
            ->where('created_at', '<=', $model->fromDateTime($cutoff))
            ->where(static fn (Builder $q) => $q->whereNull('last_published_at')->orWhere('last_published_at', '<=', $model->fromDateTime($cutoff)))
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($candidates as $channel) {
            $ids[] = (string) $channel->channel_id;
        }
        $referenced = [];
        foreach (PushActivity::blank()->newQuery()->forBinding($binding)->open()->whereIn('broadcast_channel_id', $ids)->get(['broadcast_channel_id']) as $activity) {
            $referenced[(string) $activity->broadcast_channel_id] = true;
        }

        $out = [];
        foreach ($candidates as $channel) {
            if (! isset($referenced[(string) $channel->channel_id])) {
                $out[] = $channel;
            }
        }

        return $out;
    }

    /** Delete every unreferenced channel; a failure on one channel does not stop the others. Returns how many were deleted. */
    public function cleanup(BindingRef $binding, ?int $idleHours = null): int
    {
        $deleted = 0;
        foreach ($this->unreferenced($binding, $idleHours) as $channel) {
            try {
                if ($this->delete($channel)->state === ChannelState::Deleted) {
                    $deleted++;
                }
            } catch (ChannelApiException) {
                continue;
            }
        }

        return $deleted;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Serialized publications (TransitionStore)
    // ---------------------------------------------------------------------------------------------------------

    public function find(string $id): ?BroadcastChannel
    {
        $row = BroadcastChannel::blank()->newQuery()->whereKey($id)->first();

        return $row instanceof BroadcastChannel ? $row : null;
    }

    public function snapshot(Model $row): RowSnapshot
    {
        return new RowSnapshot(
            // After an end only the end may be published again; a channel is closed by deleting it.
            $row->ended_at !== null ? ActivityState::Ending : ActivityState::Active,
            $row->state === ChannelState::Active && $row->channel_id !== null,
            $row->applied_seq,
            $row->pending_seq,
            $row->last_timestamp,
        );
    }

    public function destination(Model $row): ?Destination
    {
        return $row->destination();
    }

    public function nextSeq(Model $row): array
    {
        return $this->rows->nextSeq($row);
    }

    public function storePending(Model $row, TransitionCommand $command, TransitionPayload $payload, int $dueAt): bool
    {
        $fields = [
            'pending_seq' => $command->seq,
            'pending_payload' => $payload->toArray(),
            'pending_due_at' => $dueAt,
            'pending_attempts' => $command->fromPending ? $row->pending_attempts : 0,
        ];
        if ($payload->event === LiveActivityEvent::End && $row->ended_at === null) {
            $fields['ended_at'] = $this->rows->now();
        }

        return $this->rows->cas($row, $fields);
    }

    public function claim(Model $row, TransitionCommand $command, TransitionPayload $payload, TransitionDecision $decision): bool
    {
        $fields = [
            'applied_seq' => $command->seq,
            'last_timestamp' => $decision->timestamp,
            'last_event' => $payload->event->value,
            'last_priority' => $decision->priority?->value,
            'last_status' => 'sending',
            'last_published_at' => $this->rows->now(),
        ];
        if ($payload->state !== null) {
            $fields['last_content_state'] = $payload->state;
        }
        if ($row->pending_seq !== null && $row->pending_seq <= $command->seq) {
            $fields += ['pending_seq' => null, 'pending_payload' => null, 'pending_due_at' => null];
            if (! $command->fromPending) {
                $fields['pending_attempts'] = 0;
            }
        }
        if ($payload->event === LiveActivityEvent::End && $row->ended_at === null) {
            $fields['ended_at'] = $this->rows->now();
        }

        return $this->rows->cas($row, $fields);
    }

    public function record(Model $before, TransitionCommand $command, TransitionPayload $payload, TransitionDecision $decision, SendResult $result, int $sentUpTo): void
    {
        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $row = $this->rows->fresh($before);
            $latest = $row->applied_seq === $command->seq;
            $fields = [];

            if ($latest) {
                $fields['last_status'] = $result->status->value;
                if ($sentUpTo > ($row->last_timestamp ?? 0)) {
                    $fields['last_timestamp'] = $sentUpTo;
                }
            }

            if ($result->status === SendStatus::Accepted) {
                $fields['publications'] = $row->publications + 1;
            } elseif ($result->status === SendStatus::Deferred) {
                $attempts = ($command->fromPending ? $row->pending_attempts : 0) + 1;
                $newer = $row->applied_seq > $command->seq || ($row->pending_seq !== null && $row->pending_seq > $command->seq);
                if (! $newer && $attempts <= $this->config->maxUpdateRetries) {
                    $fields += [
                        'pending_seq' => $command->seq,
                        'pending_payload' => $payload->toArray(),
                        'pending_due_at' => $result->nextAttemptAt === null ? $this->rows->second() + 1 : (int) ceil((float) $result->nextAttemptAt->format('U.u')),
                        'pending_attempts' => $attempts,
                    ];
                }
            } elseif (in_array($result->status, [SendStatus::Skipped, SendStatus::Expired], true) && $latest) {
                $fields = array_replace($fields, [
                    'last_content_state' => $before->last_content_state,
                    'last_event' => $before->last_event,
                    'last_priority' => $before->last_priority,
                    'last_timestamp' => $before->last_timestamp,
                    'last_published_at' => $before->last_published_at,
                    'ended_at' => $before->ended_at,
                ]);
            } elseif ($result->status === SendStatus::Rejected && $result->reason->code === ReasonCode::ChannelUnknown) {
                $fields += ['state' => ChannelState::Deleted, 'closed_reason' => CloseReason::UnknownToApns, 'state_changed_at' => $this->rows->now()];
            }

            if ($fields === [] || $this->rows->cas($row, $fields)) {
                return;
            }
        }

        throw new \RuntimeException('Could not record the outcome of the publication.');
    }

    public function bindingOf(BroadcastChannel $row): BindingRef
    {
        return new BindingRef($row->scope, $row->binding_name, Provider::Apns, $row->app_id, $row->environment);
    }

    // ---------------------------------------------------------------------------------------------------------

    /**
     * Rows of the same app and environment in every scope: APNs' channel list and limit are per app and environment.
     *
     * @return Builder<BroadcastChannel>
     */
    private function appQuery(BindingRef $binding): Builder
    {
        return BroadcastChannel::blank()->newQuery()
            ->where('app_id', $binding->appId)
            ->where('environment', $binding->environment->value);
    }

    /**
     * A compare-and-set write that re-reads and retries until it lands (management writes are not ordered by tickets).
     *
     * @param  array<string, mixed>  $fields
     */
    private function force(BroadcastChannel $row, array $fields): void
    {
        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $current = $attempt === 0 ? $row : $this->rows->fresh($row);
            if ($this->rows->cas($current, $fields)) {
                if ($current !== $row) {
                    $row->setRawAttributes($current->getAttributes(), true);
                }

                return;
            }
        }

        throw new \RuntimeException('Could not update the channel row: it kept changing under concurrent writers.');
    }

    private static function assertApns(BindingRef $binding): void
    {
        if ($binding->provider !== Provider::Apns) {
            throw new InvalidDestinationException('Broadcast channels need an APNs binding.');
        }
    }
}
