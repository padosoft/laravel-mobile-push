<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\RowSnapshot;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionCommand;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionDecision;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionPayload;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionStore;

/**
 * The only writer of the Live Activities tables (activities and push-to-start tokens).
 *
 * Token kinds are disjoint: a push-to-start token (ApnsPushToStartToken) only ever starts an activity; an update
 * token (ApnsLiveActivityToken) belongs to exactly one activity row and carries its updates and its end.
 *
 * Matching rule when the app reports an update token (reportActivityToken()), first hit wins, always inside the
 * same scope + binding identity + type key:
 *  1. a row that already holds this token: the same report again, refreshed;
 *  2. `ActivityReport::$activityId`: the server row with that id;
 *  3. `ActivityReport::$clientActivityId`: the row with that ActivityKit id (a rotated token of a known activity);
 *  4. `ActivityReport::$pushToStartToken`: the newest `requested` row, without a token, created from that push-to-start
 *     token (the remote start this device answers);
 *  5. the owner: the oldest `requested` row of that owner and type without a token (FIFO);
 *  6. otherwise the activity was started locally on the device: a new `active` row.
 * A token arriving for a row that already has another one is a rotation: generation + 1, the old value is replaced.
 *
 * @implements TransitionStore<PushActivity>
 */
final readonly class ActivityRegistry implements TransitionStore
{
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private TokenIndex $index,
        private TokenCipher $cipher,
        private TypeRegistry $types,
        private LiveActivitiesConfig $config,
        private Rows $rows,
    ) {}

    // ---------------------------------------------------------------------------------------------------------
    // Push-to-start tokens
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Register (or refresh) the push-to-start token a device reports for one Swift attributes type.
     *
     * Same owner and still active: refresh only. Owner changed or the row was quarantined/invalidated: generation + 1.
     * `$previous` names the token this one replaces (the app saw it rotate): that row is updated in place with the new
     * value and a new generation instead of leaving a duplicate behind.
     */
    public function registerPushToStartToken(
        BindingRef $binding,
        LiveActivityType|string $type,
        ApnsPushToStartToken $token,
        ?Model $owner,
        ?ApnsPushToStartToken $previous = null,
    ): PushToStartToken {
        self::assertApns($binding);
        $key = $this->types->keyOf($this->types->resolve($type));

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $row = $this->findPushToStart($binding, $key, $token)
                ?? ($previous === null ? null : $this->findPushToStart($binding, $key, $previous));

            try {
                return $row === null
                    ? $this->createPushToStart($binding, $key, $token, $owner)
                    : $this->touchPushToStart($row, $binding, $token, $owner);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not register the push-to-start token.');
    }

    /**
     * Active push-to-start tokens of these owners for a type, in one scope + binding.
     *
     * @param  iterable<Model>  $owners
     * @return list<PushToStartToken>
     */
    public function pushToStartTokensFor(BindingRef $binding, string $typeKey, iterable $owners): array
    {
        $out = [];
        foreach ($owners as $owner) {
            $rows = PushToStartToken::blank()->newQuery()
                ->forBinding($binding)
                ->where('type_key', $typeKey)
                ->where('state', TokenState::Active->value)
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', PushToStartToken::keyOf($owner))
                ->orderBy('registered_at')
                ->get();

            foreach ($rows as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Activities reported by the app
    // ---------------------------------------------------------------------------------------------------------

    /** Register the update token the app reports for one activity; see the class docblock for the matching rule. */
    public function reportActivityToken(
        BindingRef $binding,
        LiveActivityType|string $type,
        ApnsLiveActivityToken $token,
        ?Model $owner,
        ActivityReport $report = new ActivityReport,
    ): PushActivity {
        self::assertApns($binding);
        $resolved = $this->types->resolve($type);
        $key = $this->types->keyOf($resolved);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $row = $this->match($binding, $key, $token, $owner, $report);

            try {
                if ($row === null) {
                    return $this->createLocal($binding, $resolved, $key, $token, $owner, $report);
                }

                $attached = $this->attachToken($row, $binding, $token, $owner, $report);
                if ($attached !== null) {
                    return $attached;
                }
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not register the activity token: the row kept changing under concurrent writers.');
    }

    /**
     * Record what the app says the device shows. Informational: it never changes `state`, `end_accepted_at` or what
     * the server sends. A report older than the one stored is ignored (reports may arrive out of order).
     */
    public function reportClientState(PushActivity $activity, ClientActivityState $state, ?\DateTimeInterface $at = null): PushActivity
    {
        $when = $at === null ? $this->rows->now() : CarbonImmutable::instance($at)->setTimezone(date_default_timezone_get());

        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $row = $attempt === 0 ? $activity : $this->rows->fresh($activity);

            if ($row->client_reported_at !== null && $row->client_reported_at->getTimestamp() > $when->getTimestamp()) {
                return $row;
            }
            if ($this->rows->cas($row, ['client_reported_state' => $state, 'client_reported_at' => $when])) {
                return $row;
            }
        }

        throw new \RuntimeException('Could not record the client-reported state.');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Provider outcomes
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Apply a FINAL provider outcome to the token row it was sent to: Quarantine or Invalidate, only for the
     * generation that was attempted. A late answer about an old generation changes nothing.
     */
    public function applyResult(SendResult $result): TokenChange
    {
        $ref = $result->destination->ref();

        if ($result->status === SendStatus::OutcomeUnknown) {
            return TokenChange::UnknownOutcome;
        }
        if ($result->destinationAction === DestinationAction::None) {
            return TokenChange::NoAction;
        }
        if ($ref === null) {
            return TokenChange::RowMissing;
        }

        $quarantine = $result->destinationAction === DestinationAction::Quarantine;

        return match ($result->destination->kind()) {
            DestinationKind::ApnsPushToStartToken => $this->applyToPushToStart($ref->id, $ref->generation, $quarantine, $result),
            DestinationKind::ApnsLiveActivityToken => $this->applyToActivity($ref->id, $ref->generation, $quarantine, $result),
            default => TokenChange::NoAction,
        };
    }

    // ---------------------------------------------------------------------------------------------------------
    // Remote start
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The `requested` row of a remote start to one push-to-start token, written BEFORE the request goes out.
     *
     * @param  array<array-key, mixed>  $state
     */
    public function createRequested(
        BindingRef $binding,
        LiveActivityType $type,
        PushToStartToken $from,
        array $state,
        int $timestamp,
        ?string $channelId,
        ?\DateTimeInterface $staleAt,
    ): PushActivity {
        $now = $this->rows->now();
        $row = PushActivity::blank();
        $row->forceFill([
            ...$this->bindingFields($binding),
            'owner_type' => $from->owner_type,
            'owner_id' => $from->owner_id,
            'type_key' => $from->type_key,
            'swift_name' => $type->swiftName(),
            'type_version' => $type->version(),
            'push_to_start_id' => $from->id,
            'token_generation' => 0,
            'broadcast_channel_id' => $channelId,
            'state' => ActivityState::Requested,
            'last_content_state' => $state,
            'last_event' => LiveActivityEvent::Start->value,
            'last_status' => 'sending',
            'last_priority' => 10,
            'last_timestamp' => $timestamp,
            'last_sent_at' => $now,
            'dispatch_seq' => 1,
            'applied_seq' => 1,
            'pending_attempts' => 0,
            'hp_count' => 0,
            'version' => 1,
            'stale_at' => $staleAt === null ? null : CarbonImmutable::instance($staleAt),
            'local_expires_at' => $now->addHours($this->config->localExpiryHours),
        ]);
        $row->save();

        return $row;
    }

    /** What the pipeline answered for the remote start of this row. A start is never retried by the server. */
    public function recordStart(PushActivity $row, SendResult $result, int $sentUpTo): PushActivity
    {
        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $current = $attempt === 0 ? $row : $this->rows->fresh($row);
            $now = $this->rows->now();
            $fields = [
                'start_status' => $result->status->value,
                'last_status' => $result->status->value,
                'last_timestamp' => max($current->last_timestamp ?? 0, $sentUpTo),
            ];

            if ($result->status === SendStatus::Accepted) {
                $fields['started_at'] = $now;
                $fields['last_accepted_at'] = $now;
            } elseif (in_array($result->status, [SendStatus::Rejected, SendStatus::Failed, SendStatus::Skipped, SendStatus::Expired], true)
                && $current->state === ActivityState::Requested) {
                // Known not accepted: no activity was started on the device.
                $fields += ['state' => ActivityState::Ended, 'closed_reason' => CloseReason::StartNotAccepted, 'closed_at' => $now];
            }

            if ($this->rows->cas($current, $fields)) {
                return $current;
            }
        }

        throw new \RuntimeException('Could not record the outcome of the start.');
    }

    /** Remove `requested` rows whose start never left the server (globally invalid message). */
    public function discardRequested(PushActivity $row): void
    {
        $row->newQuery()->whereKey($row->getKey())->where('state', ActivityState::Requested->value)->whereNull('update_token')->delete();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Serialized transitions (TransitionStore)
    // ---------------------------------------------------------------------------------------------------------

    public function find(string $id): ?PushActivity
    {
        $row = PushActivity::blank()->newQuery()->whereKey($id)->first();

        return $row instanceof PushActivity ? $row : null;
    }

    public function snapshot(Model $row): RowSnapshot
    {
        return new RowSnapshot(
            $row->state,
            $row->hasUsableToken(),
            $row->applied_seq,
            $row->pending_seq,
            $row->last_timestamp,
            $row->hp_window_started_at,
            $row->hp_count,
        );
    }

    public function destination(Model $row): ?Destination
    {
        return $row->hasUsableToken() ? $row->destination() : null;
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

        if ($payload->event === LiveActivityEvent::End) {
            // The end is decided now: from here on no update may go out, even before the end itself does.
            $fields += ['state' => ActivityState::Ending, 'end_requested_at' => $row->end_requested_at ?? $this->rows->now()];
        }

        return $this->rows->cas($row, $fields);
    }

    public function claim(Model $row, TransitionCommand $command, TransitionPayload $payload, TransitionDecision $decision): bool
    {
        $now = $this->rows->now();
        $fields = [
            'applied_seq' => $command->seq,
            'last_timestamp' => $decision->timestamp,
            'last_event' => $payload->event->value,
            'last_priority' => $decision->priority?->value,
            'last_status' => 'sending',
            'last_sent_at' => $now,
            'hp_window_started_at' => $decision->hpWindowStartedAt,
            'hp_count' => $decision->hpCount,
        ];

        if ($payload->state !== null) {
            $fields['last_content_state'] = $payload->state;
        }
        if ($payload->staleAt !== null) {
            $fields['stale_at'] = CarbonImmutable::createFromTimestamp($payload->staleAt)->setTimezone(date_default_timezone_get());
        }
        if ($row->pending_seq !== null && $row->pending_seq <= $command->seq) {
            $fields += ['pending_seq' => null, 'pending_payload' => null, 'pending_due_at' => null];
            if (! $command->fromPending) {
                $fields['pending_attempts'] = 0;
            }
        }
        if ($payload->event === LiveActivityEvent::End) {
            $fields['state'] = ActivityState::Ending;
            $fields['end_requested_at'] = $row->end_requested_at ?? $now;
            if ($payload->dismissAt !== null) {
                $fields['dismissal_at'] = CarbonImmutable::createFromTimestamp($payload->dismissAt)->setTimezone(date_default_timezone_get());
            }
        }

        return $this->rows->cas($row, $fields);
    }

    public function record(Model $before, TransitionCommand $command, TransitionPayload $payload, TransitionDecision $decision, SendResult $result, int $sentUpTo): void
    {
        $tokenChange = $this->applyResult($result);
        if ($tokenChange === TokenChange::Invalidated || $tokenChange === TokenChange::Quarantined) {
            // applyResult() already closed / suspended the row under its own compare-and-set.
            $this->recordStatus($before, $command, $result, $sentUpTo);

            return;
        }

        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $row = $this->rows->fresh($before);
            $latest = $row->applied_seq === $command->seq;
            $fields = $this->outcomeFields($before, $row, $latest, $command, $payload, $result, $sentUpTo);

            if ($fields === [] || $this->rows->cas($row, $fields)) {
                return;
            }
        }

        throw new \RuntimeException('Could not record the outcome of the transition.');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Pruning
    // ---------------------------------------------------------------------------------------------------------

    /** Mark open activities past their local expiry as `expired` (the server stops sending). Returns how many. */
    public function expireDue(int $limit = 500): int
    {
        $now = $this->rows->now();
        $model = PushActivity::blank();
        $count = 0;

        $due = $model->newQuery()
            ->open()
            ->whereNotNull('local_expires_at')
            ->where('local_expires_at', '<=', $model->fromDateTime($now))
            ->orderBy('local_expires_at')
            ->limit($limit)
            ->get();

        foreach ($due as $row) {
            for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
                $current = $attempt === 0 ? $row : $this->rows->fresh($row);
                if ($current->state->isClosed()) {
                    break;
                }
                if ($this->rows->cas($current, [
                    'state' => ActivityState::Expired,
                    'closed_reason' => CloseReason::LocalExpiry,
                    'closed_at' => $now,
                    'pending_seq' => null,
                    'pending_payload' => null,
                    'pending_due_at' => null,
                ])) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /** Delete closed activities (and invalidated push-to-start tokens) closed before the cutoff, in bounded chunks. */
    public function deleteClosedBefore(\DateTimeInterface $cutoff, int $chunk = 500): int
    {
        $model = PushActivity::blank();
        $at = $model->fromDateTime($cutoff);
        $total = 0;

        do {
            $ids = $model->newQuery()
                ->whereIn('state', [ActivityState::Ended->value, ActivityState::Expired->value])
                ->where('closed_at', '<', $at)
                ->orderBy('closed_at')
                ->limit($chunk)
                ->pluck($model->getKeyName())
                ->all();
            if ($ids !== []) {
                $total += (int) $model->newQuery()->whereIn($model->getKeyName(), $ids)->toBase()->delete();
            }
        } while (count($ids) === $chunk);

        $tokens = PushToStartToken::blank();
        $total += (int) $tokens->newQuery()
            ->where('state', TokenState::Invalidated->value)
            ->where('state_changed_at', '<', $tokens->fromDateTime($cutoff))
            ->toBase()
            ->delete();

        return $total;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function outcomeFields(PushActivity $before, PushActivity $row, bool $latest, TransitionCommand $command, TransitionPayload $payload, SendResult $result, int $sentUpTo): array
    {
        $now = $this->rows->now();
        $isEnd = $payload->event === LiveActivityEvent::End;
        $fields = [];

        if ($latest) {
            $fields['last_status'] = $result->status->value;
            if ($sentUpTo > ($row->last_timestamp ?? 0)) {
                $fields['last_timestamp'] = $sentUpTo;
            }
        }

        switch ($result->status) {
            case SendStatus::Accepted:
                $fields['last_accepted_at'] = $now;
                if ($isEnd && ! $row->state->isClosed()) {
                    $fields += ['state' => ActivityState::Ended, 'end_accepted_at' => $now, 'closed_at' => $now, 'closed_reason' => CloseReason::EndAccepted];
                } elseif ($this->config->localExpiryFrom === 'last_update' && ! $row->state->isClosed()) {
                    $fields['local_expires_at'] = $now->addHours($this->config->localExpiryHours);
                }
                break;

            case SendStatus::Deferred:
                // A planned retry (a replaceable update/end after an unknown outcome, or a throttle): keep it as the
                // row's pending transition, unless something newer is already applied or pending.
                $attempts = ($command->fromPending ? $row->pending_attempts : 0) + 1;
                $newer = $row->applied_seq > $command->seq || ($row->pending_seq !== null && $row->pending_seq > $command->seq);
                if (! $newer && ! $row->state->isClosed() && $attempts <= $this->config->maxUpdateRetries) {
                    $due = $result->nextAttemptAt === null ? $this->rows->second() + 1 : (int) ceil((float) $result->nextAttemptAt->format('U.u'));
                    $fields += [
                        'pending_seq' => $command->seq,
                        'pending_payload' => $payload->toArray(),
                        'pending_due_at' => $due,
                        'pending_attempts' => $attempts,
                    ];
                }
                break;

            case SendStatus::Skipped:
            case SendStatus::Expired:
                // Nothing left the server: undo the claim so the stored state is what was really sent.
                if ($latest) {
                    $fields = array_replace($fields, [
                        'last_content_state' => $before->last_content_state,
                        'last_event' => $before->last_event,
                        'last_priority' => $before->last_priority,
                        'last_timestamp' => $before->last_timestamp,
                        'last_sent_at' => $before->last_sent_at,
                        'hp_window_started_at' => $before->hp_window_started_at,
                        'hp_count' => $before->hp_count,
                        'stale_at' => $before->stale_at,
                    ]);
                    if ($isEnd && $row->state === ActivityState::Ending && $before->state !== ActivityState::Ending) {
                        $fields += ['state' => $before->state, 'end_requested_at' => $before->end_requested_at, 'dismissal_at' => $before->dismissal_at];
                    }
                }
                break;

            default:
                // OutcomeUnknown, Rejected, Failed, Simulated, Validated: the claim stands as what was sent.
                break;
        }

        return $fields;
    }

    private function recordStatus(PushActivity $before, TransitionCommand $command, SendResult $result, int $sentUpTo): void
    {
        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $row = $this->rows->fresh($before);
            if ($row->applied_seq !== $command->seq) {
                return;
            }
            if ($this->rows->cas($row, ['last_status' => $result->status->value, 'last_timestamp' => max($row->last_timestamp ?? 0, $sentUpTo)])) {
                return;
            }
        }
    }

    private function applyToActivity(string $id, int $generation, bool $quarantine, SendResult $result): TokenChange
    {
        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $row = $this->find($id);
            if ($row === null) {
                return TokenChange::RowMissing;
            }
            if ($row->token_generation !== $generation) {
                return TokenChange::GenerationChanged;
            }
            if ($row->token_state === TokenState::Invalidated || ($quarantine && $row->token_state === TokenState::Quarantined)) {
                return TokenChange::AlreadyClosed;
            }

            $now = $this->rows->now();
            $fields = $quarantine
                ? ['token_state' => TokenState::Quarantined]
                : ['token_state' => TokenState::Invalidated];

            if (! $quarantine && ! $row->state->isClosed()) {
                // APNs says this activity's token is gone: nothing can reach the activity any more.
                $fields += ['state' => ActivityState::Ended, 'closed_reason' => CloseReason::TokenInvalidated, 'closed_at' => $now,
                    'pending_seq' => null, 'pending_payload' => null, 'pending_due_at' => null];
            }

            if ($this->rows->cas($row, $fields)) {
                return $quarantine ? TokenChange::Quarantined : TokenChange::Invalidated;
            }
        }

        throw new \RuntimeException('Could not apply the provider outcome to the activity.');
    }

    private function applyToPushToStart(string $id, int $generation, bool $quarantine, SendResult $result): TokenChange
    {
        $model = PushToStartToken::blank();

        return $model->getConnection()->transaction(function () use ($model, $id, $generation, $quarantine, $result): TokenChange {
            $row = $model->newQuery()->whereKey($id)->lockForUpdate()->first();

            if (! $row instanceof PushToStartToken) {
                return TokenChange::RowMissing;
            }
            if ($row->generation !== $generation) {
                return TokenChange::GenerationChanged;
            }
            if ($row->state === TokenState::Invalidated || ($quarantine && $row->state === TokenState::Quarantined)) {
                return TokenChange::AlreadyClosed;
            }
            if (! $quarantine && $result->invalidatedAtMs !== null && $result->invalidatedAtMs <= $row->registered_at->getTimestamp() * 1000) {
                // APNs' invalidation timestamp predates this registration: the token was registered again since.
                return TokenChange::GenerationChanged;
            }

            $reason = $result->reason->rawReason ?? $result->reason->code->value;
            $row->state = $quarantine ? TokenState::Quarantined : TokenState::Invalidated;
            $row->state_reason = substr($reason, 0, 64);
            $row->state_changed_at = $this->rows->now();
            $row->save();

            return $quarantine ? TokenChange::Quarantined : TokenChange::Invalidated;
        });
    }

    private function match(BindingRef $binding, string $key, ApnsLiveActivityToken $token, ?Model $owner, ActivityReport $report): ?PushActivity
    {
        $base = fn () => PushActivity::blank()->newQuery()->forBinding($binding)->where('type_key', $key);

        $same = $base()->whereIn('update_token_index', $this->index->candidates($token, $binding->scope))->first();
        if ($same instanceof PushActivity) {
            return $same;
        }

        if ($report->activityId !== null) {
            $byId = $base()->whereKey($report->activityId)->first();
            if ($byId instanceof PushActivity) {
                return $byId;
            }
        }

        if ($report->clientActivityId !== null) {
            $byClient = $base()->where('client_activity_id', $report->clientActivityId)->first();
            if ($byClient instanceof PushActivity) {
                return $byClient;
            }
        }

        $waiting = fn () => $base()->where('state', ActivityState::Requested->value)->whereNull('update_token');

        if ($report->pushToStartToken !== null) {
            $from = $this->findPushToStart($binding, $key, $report->pushToStartToken);
            if ($from !== null) {
                $byStart = $waiting()->where('push_to_start_id', $from->id)->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($byStart instanceof PushActivity) {
                    return $byStart;
                }
            }
        }

        if ($owner !== null) {
            $byOwner = $waiting()
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', PushActivity::keyOf($owner))
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();
            if ($byOwner instanceof PushActivity) {
                return $byOwner;
            }
        }

        return null;
    }

    private function attachToken(PushActivity $row, BindingRef $binding, ApnsLiveActivityToken $token, ?Model $owner, ActivityReport $report): ?PushActivity
    {
        for ($attempt = 0; $attempt < Rows::MAX_ATTEMPTS; $attempt++) {
            $current = $attempt === 0 ? $row : $this->rows->fresh($row);
            $index = $this->index->index($token, $binding->scope);
            $same = $current->update_token_index !== null && $this->index->matches($current->update_token_index, $token, $binding->scope);

            $fields = ['binding_name' => $binding->name];

            if (! $same) {
                // First token of a remote start, or a rotation: a new generation; a late answer about the old one is ignored.
                $fields += [
                    'update_token' => $this->cipher->encrypt($token->value(), $binding->scope, DestinationKind::ApnsLiveActivityToken->value),
                    'update_token_index' => $index,
                    'token_generation' => $current->token_generation + 1,
                    'token_state' => TokenState::Active,
                ];
            } else {
                $fields['update_token_index'] = $index;
                if ($current->update_token !== null && $this->cipher->needsRotation($current->update_token)) {
                    $fields['update_token'] = $this->cipher->encrypt($token->value(), $binding->scope, DestinationKind::ApnsLiveActivityToken->value);
                }
                if ($current->token_state !== TokenState::Active && ! $current->state->isClosed()) {
                    // Reported again by the app after a quarantine: usable again, under a new generation.
                    $fields += ['token_state' => TokenState::Active, 'token_generation' => $current->token_generation + 1];
                }
            }

            if ($current->state === ActivityState::Requested) {
                $fields['state'] = ActivityState::Active;
            }
            if ($current->client_activity_id === null && $report->clientActivityId !== null) {
                $fields['client_activity_id'] = $report->clientActivityId;
            }
            if ($current->broadcast_channel_id === null && $report->channelId !== null) {
                $fields['broadcast_channel_id'] = $report->channelId;
            }
            if ($current->owner_type === null && $owner !== null) {
                $fields += ['owner_type' => $owner->getMorphClass(), 'owner_id' => PushActivity::keyOf($owner)];
            }

            if ($this->rows->cas($current, $fields)) {
                return $current;
            }
        }

        return null;
    }

    private function createLocal(BindingRef $binding, LiveActivityType $type, string $key, ApnsLiveActivityToken $token, ?Model $owner, ActivityReport $report): PushActivity
    {
        $now = $this->rows->now();
        $started = $report->startedAt === null ? $now : CarbonImmutable::instance($report->startedAt)->setTimezone(date_default_timezone_get());
        $row = PushActivity::blank();
        $row->forceFill([
            ...$this->bindingFields($binding),
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner === null ? null : PushActivity::keyOf($owner),
            'type_key' => $key,
            'swift_name' => $type->swiftName(),
            'type_version' => $type->version(),
            'client_activity_id' => $report->clientActivityId,
            'update_token' => $this->cipher->encrypt($token->value(), $binding->scope, DestinationKind::ApnsLiveActivityToken->value),
            'update_token_index' => $this->index->index($token, $binding->scope),
            'token_generation' => 1,
            'token_state' => TokenState::Active,
            'broadcast_channel_id' => $report->channelId,
            'state' => ActivityState::Active,
            'last_content_state' => $report->state,
            'dispatch_seq' => 0,
            'applied_seq' => 0,
            'pending_attempts' => 0,
            'hp_count' => 0,
            'version' => 1,
            'started_at' => $started,
            'local_expires_at' => $started->addHours($this->config->localExpiryHours),
        ]);
        $row->save();

        return $row;
    }

    private function findPushToStart(BindingRef $binding, string $key, ApnsPushToStartToken $token): ?PushToStartToken
    {
        $row = PushToStartToken::blank()->newQuery()
            ->where('binding_identity', $binding->identity())
            ->where('type_key', $key)
            ->whereIn('token_index', $this->index->candidates($token, $binding->scope))
            ->first();

        return $row instanceof PushToStartToken ? $row : null;
    }

    private function createPushToStart(BindingRef $binding, string $key, ApnsPushToStartToken $token, ?Model $owner): PushToStartToken
    {
        $now = $this->rows->now();
        $row = PushToStartToken::blank();
        $row->forceFill([
            ...$this->bindingFields($binding),
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner === null ? null : PushToStartToken::keyOf($owner),
            'type_key' => $key,
            'token' => $this->cipher->encrypt($token->value(), $binding->scope, DestinationKind::ApnsPushToStartToken->value),
            'token_index' => $this->index->index($token, $binding->scope),
            'generation' => 1,
            'state' => TokenState::Active,
            'state_changed_at' => $now,
            'registered_at' => $now,
            'last_seen_at' => $now,
        ]);
        $row->save();

        return $row;
    }

    private function touchPushToStart(PushToStartToken $found, BindingRef $binding, ApnsPushToStartToken $token, ?Model $owner): PushToStartToken
    {
        $model = PushToStartToken::blank();

        return $model->getConnection()->transaction(function () use ($model, $found, $binding, $token, $owner): PushToStartToken {
            $row = $model->newQuery()->whereKey($found->getKey())->lockForUpdate()->first();
            if (! $row instanceof PushToStartToken) {
                return $this->createPushToStart($binding, $found->type_key, $token, $owner);
            }

            $now = $this->rows->now();
            $ownerType = $owner?->getMorphClass();
            $ownerId = $owner === null ? null : PushToStartToken::keyOf($owner);
            $index = $this->index->index($token, $binding->scope);
            $sameToken = $this->index->matches($row->token_index, $token, $binding->scope);

            if (! $sameToken || $row->state !== TokenState::Active || $row->owner_type !== $ownerType || $row->owner_id !== $ownerId) {
                $row->generation = $row->generation + 1;
                $row->owner_type = $ownerType;
                $row->owner_id = $ownerId;
                $row->state = TokenState::Active;
                $row->state_reason = null;
                $row->state_changed_at = $now;
                $row->registered_at = $now;
            }
            if (! $sameToken || $this->cipher->needsRotation($row->token)) {
                $row->token = $this->cipher->encrypt($token->value(), $binding->scope, DestinationKind::ApnsPushToStartToken->value);
            }
            $row->token_index = $index;
            $row->binding_name = $binding->name;
            $row->last_seen_at = $now;
            $row->save();

            return $row;
        });
    }

    /** @return array<string, mixed> */
    private function bindingFields(BindingRef $binding): array
    {
        return [
            'scope' => $binding->scope,
            'binding_name' => $binding->name,
            'binding_identity' => $binding->identity(),
            'app_id' => $binding->appId,
            'environment' => $binding->environment,
        ];
    }

    private static function assertApns(BindingRef $binding): void
    {
        if ($binding->provider !== Provider::Apns) {
            throw new InvalidDestinationException('Live Activities need an APNs binding.');
        }
    }
}
