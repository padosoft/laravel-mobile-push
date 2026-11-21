<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\DecisionKind;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\HourlyLimiter;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionCommand;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionPayload;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionRules;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionStore;

/**
 * The public API of Live Activities, built on PushDispatcher.
 *
 *  - start(): a remote start to the owner's push-to-start tokens. One `requested` row per token is written before
 *    the request; a start is not replaceable, so an unknown outcome is never retried (the row records it).
 *  - for($activity)->update()/end(): transitions of one activity, through its own update token.
 *  - broadcast($channel)->update()/end(): publications on a broadcast channel (iOS 18+). A channel cannot start.
 *
 * Serialization: every transition gets a ticket number at dispatch and is applied under compare-and-set on the
 * row version (TransitionRules decides; the registries write). The request carries the current Unix second; a
 * second transition in the same second is coalesced as the row's pending transition (latest state wins) and goes
 * out from the next second through flush() / flushDue() / any newer transition. The stored `last_timestamp` and
 * `last_content_state` are those of the last request that left the server.
 */
final class ActivityManager
{
    private const MAX_ATTEMPTS = 8;

    private readonly TransitionRules $rules;

    public function __construct(
        private readonly PushDispatcher $dispatcher,
        private readonly ActivityRegistry $activities,
        private readonly ChannelRegistry $channels,
        private readonly TypeRegistry $types,
        private readonly LiveActivitiesConfig $config,
        private readonly Rows $rows,
    ) {
        $this->rules = new TransitionRules(new HourlyLimiter($config->highPriorityPerHour));
    }

    /**
     * @param  array<array-key, mixed>  $attributes  static data of the Swift ActivityAttributes
     * @param  array<array-key, mixed>  $state  the initial ContentState
     */
    public function start(LiveActivityType|string $type, array $attributes, array $state): PendingStart
    {
        return new PendingStart($this, $this->types->resolve($type), $attributes, $state, $this->config->defaultPriority);
    }

    public function for(PushActivity|string $activity): ActivityHandle
    {
        $row = $activity instanceof PushActivity ? $activity : $this->activities->find($activity);
        if ($row === null) {
            throw new \InvalidArgumentException('No Live Activity with this id.');
        }

        return new ActivityHandle($this, $row, $this->config->defaultPriority);
    }

    /** A channel row, or its APNs channel id in the current scope of the default binding. */
    public function broadcast(BroadcastChannel|string $channel, ?string $connection = null, ?string $scope = null): ChannelHandle
    {
        $row = $channel instanceof BroadcastChannel ? $channel : $this->channels->findByChannelId($this->binding($connection, $scope), $channel);
        if ($row === null) {
            throw new \InvalidArgumentException('No broadcast channel with this id in this scope and binding: create it with ChannelRegistry::create() first.');
        }

        return new ChannelHandle($this, $row, $this->config->defaultPriority);
    }

    public function channels(): ChannelRegistry
    {
        return $this->channels;
    }

    public function registry(): ActivityRegistry
    {
        return $this->activities;
    }

    /** Create a broadcast channel for the default (or given) binding. */
    public function createChannel(StoragePolicy $policy = StoragePolicy::NoStorage, ?string $label = null, ?string $connection = null, ?string $scope = null): BroadcastChannel
    {
        return $this->channels->create($this->binding($connection, $scope), $policy, $label);
    }

    /**
     * Apply a ticket captured at dispatch (a queued job). Safe whenever it runs: a ticket older than a transition
     * already applied or pending is Skipped, and nothing is sent after an end or the local expiry.
     */
    public function replay(ActivityTicket $ticket): TransitionResult
    {
        $command = new TransitionCommand($ticket->payload->event, $ticket->seq, $ticket->payload->priority);

        return $ticket->target === ActivityTicket::TARGET_CHANNEL
            ? $this->execute($this->channels, $ticket->id, $command, $ticket->payload, true)
            : $this->execute($this->activities, $ticket->id, $command, $ticket->payload, false);
    }

    /** Send the row's pending transition (coalesced, or a planned retry) if its second has come. */
    public function flush(PushActivity|BroadcastChannel $row): TransitionResult
    {
        $broadcast = $row instanceof BroadcastChannel;
        $store = $broadcast ? $this->channels : $this->activities;
        $fresh = $store->find($row->id);

        if ($fresh === null || $fresh->pending_seq === null || $fresh->pending_payload === null) {
            return $this->local(TransitionOutcome::Skipped, SendStatus::Skipped, ReasonCode::None, 'Nothing is pending.', $fresh, $broadcast);
        }

        if ($fresh->pending_due_at !== null && $this->rows->second() < $fresh->pending_due_at) {
            return new TransitionResult(
                TransitionOutcome::Deferred, SendStatus::Deferred,
                Reason::of(ReasonCode::PolicyFrequencyCapped, null, 'The pending transition goes out from its due second.'),
                null, $fresh, $fresh->pending_seq,
                nextAttemptAt: (new \DateTimeImmutable)->setTimestamp($fresh->pending_due_at), broadcast: $broadcast,
            );
        }

        $payload = TransitionPayload::fromArray($fresh->pending_payload);
        $command = new TransitionCommand($payload->event, $fresh->pending_seq, $payload->priority, fromPending: true);

        return $broadcast
            ? $this->execute($this->channels, $fresh->id, $command, $payload, true)
            : $this->execute($this->activities, $fresh->id, $command, $payload, false);
    }

    /**
     * Flush every pending transition whose second has come (for a scheduler or a worker loop).
     *
     * @return list<TransitionResult>
     */
    public function flushDue(int $limit = 100): array
    {
        $now = $this->rows->second();
        $out = [];

        foreach (PushActivity::blank()->newQuery()->whereNotNull('pending_seq')->where('pending_due_at', '<=', $now)->orderBy('pending_due_at')->limit($limit)->get() as $row) {
            $out[] = $this->flush($row);
        }
        foreach (BroadcastChannel::blank()->newQuery()->whereNotNull('pending_seq')->where('pending_due_at', '<=', $now)->orderBy('pending_due_at')->limit($limit)->get() as $row) {
            $out[] = $this->flush($row);
        }

        return $out;
    }

    /** The binding of a connection name (default: mobile-push.live_activities.binding) in a scope (default: current). */
    public function binding(?string $connection = null, ?string $scope = null): BindingRef
    {
        return $this->dispatcher->binding($connection ?? $this->config->binding, $scope);
    }

    /** @internal used by the builders */
    public function dispatchTicket(PushActivity|BroadcastChannel $row, TransitionPayload $payload): ActivityTicket
    {
        if ($row instanceof BroadcastChannel) {
            [$seq, $fresh] = $this->channels->nextSeq($row);

            return new ActivityTicket(ActivityTicket::TARGET_CHANNEL, $fresh->id, $seq, $fresh->version, $payload);
        }

        [$seq, $fresh] = $this->activities->nextSeq($row);

        return new ActivityTicket(ActivityTicket::TARGET_ACTIVITY, $fresh->id, $seq, $fresh->version, $payload);
    }

    /** @internal used by PendingStart */
    public function sendStart(PendingStart $start): StartResult
    {
        $binding = $this->binding($start->connection, $start->scope);
        $key = $this->types->keyOf($start->type);
        $tokens = $this->activities->pushToStartTokensFor($binding, $key, $start->owners);

        if ($tokens === []) {
            return new StartResult([], []);
        }

        $message = $start->message();
        $timestamp = $this->rows->second();
        $options = $this->dispatcher->defaultOptions($binding);
        $options = $options->withRetryPolicy(self::neverRetryUnknown($options->retryPolicy));

        if ($options->effectiveMode === ExecutionMode::Simulate) {
            // Nothing is requested from the provider: report what would happen, write nothing.
            $destinations = array_map(static fn (PushToStartToken $t): Destination => $t->destination(), $tokens);

            return new StartResult([], iterator_to_array($this->dispatcher->send($binding, $message, $destinations, $options)->results(), false));
        }

        /** @var array<string, PushActivity> $rows keyed by push-to-start row id */
        $rows = [];
        $destinations = [];
        foreach ($tokens as $token) {
            $rows[$token->id] = $this->activities->createRequested($binding, $start->type, $token, $start->state, $timestamp, $start->inputPushChannel, $start->staleAt);
            $destinations[] = $token->destination();
        }

        try {
            /** @var list<SendResult> $results */
            $results = iterator_to_array($this->dispatcher->send($binding, $message, $destinations, $options)->results(), false);
        } catch (\Throwable $e) {
            // Globally invalid before the network: no start left the server.
            foreach ($rows as $row) {
                $this->activities->discardRequested($row);
            }

            throw $e;
        }

        $sentUpTo = max($timestamp, $this->rows->second());
        $activities = [];
        $ordered = [];
        foreach ($results as $result) {
            $id = $result->destination->ref()->id ?? null;
            $row = $id === null ? null : ($rows[$id] ?? null);
            if ($row === null) {
                continue;
            }
            $position = count($ordered);
            $ordered[] = $result;
            $this->activities->applyResult($result);

            if (self::nothingLeft($result->status)) {
                // Simulated, refused by safe mode or a gate, expired, or deferred (a start is never re-sent by the
                // server): nothing was requested from the provider, so no activity row stays behind.
                $this->activities->discardRequested($row);

                continue;
            }

            $activities[$position] = $this->activities->recordStart($row, $result, $sentUpTo);
        }

        return new StartResult($activities, $ordered);
    }

    /** @internal used by the builders */
    public function sendTransition(PushActivity|BroadcastChannel $row, TransitionPayload $payload): TransitionResult
    {
        $binding = $this->bindingFor($row);
        if ($binding !== null && $this->dispatcher->defaultOptions($binding)->effectiveMode === ExecutionMode::Simulate) {
            // Simulation numbers nothing and writes nothing: the would-be next ticket is only used to decide.
            $fresh = $row instanceof BroadcastChannel ? $this->channels->find($row->id) : $this->activities->find($row->id);
            $command = new TransitionCommand($payload->event, ($fresh ?? $row)->dispatch_seq + 1, $payload->priority);

            return $row instanceof BroadcastChannel
                ? $this->execute($this->channels, $row->id, $command, $payload, true)
                : $this->execute($this->activities, $row->id, $command, $payload, false);
        }

        return $this->replay($this->dispatchTicket($row, $payload));
    }

    /** Statuses of a send that prove nothing was requested from the provider by this call. */
    private static function nothingLeft(SendStatus $status): bool
    {
        return in_array($status, [SendStatus::Simulated, SendStatus::Skipped, SendStatus::Expired, SendStatus::Deferred], true);
    }

    /**
     * @template T of Model
     *
     * @param  TransitionStore<T>  $store
     */
    private function execute(TransitionStore $store, string $id, TransitionCommand $command, TransitionPayload $payload, bool $broadcast): TransitionResult
    {
        $row = $store->find($id);
        if ($row === null) {
            return $this->local(TransitionOutcome::Skipped, SendStatus::Skipped, ReasonCode::PolicyIneligible, 'The row no longer exists.', null, $broadcast, $command->seq);
        }

        $binding = $this->bindingFor($row);
        if ($binding === null) {
            return $this->local(TransitionOutcome::Skipped, SendStatus::Skipped, ReasonCode::BindingMismatch,
                'The connection this row was created for no longer resolves to the same app identity.', $row, $broadcast, $command->seq);
        }

        $options = $this->dispatcher->defaultOptions($binding);
        if ($options->effectiveMode === ExecutionMode::Simulate) {
            return $this->simulate($store, $row, $command, $payload, $binding, $options, $broadcast);
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $now = $this->rows->second();
            $decision = $this->rules->decide($store->snapshot($row), $command, $now);

            if ($decision->kind === DecisionKind::Skip) {
                if ($decision->expire && ! $store->markExpired($row)) {
                    // The row moved meanwhile: decide again on the new version.
                    $row = $store->find($id) ?? $row;

                    continue;
                }

                return new TransitionResult(TransitionOutcome::Skipped, SendStatus::Skipped, $decision->reason, null, $row, $command->seq, broadcast: $broadcast);
            }

            if ($decision->kind === DecisionKind::Defer) {
                $due = (int) $decision->dueAt;
                if ($store->storePending($row, $command, $payload, $due)) {
                    return new TransitionResult(TransitionOutcome::Deferred, SendStatus::Deferred, $decision->reason, null, $row, $command->seq,
                        nextAttemptAt: (new \DateTimeImmutable)->setTimestamp($due), broadcast: $broadcast);
                }
                $row = $store->find($id) ?? $row;

                continue;
            }

            $before = clone $row;
            $priority = $decision->priority ?? $payload->priority;
            if (! $store->claim($row, $command, $payload, $decision)) {
                $row = $store->find($id) ?? $row;

                continue;
            }

            $destination = $store->destination($row);
            if ($destination === null) {
                throw new \LogicException('A deliverable row has no destination.');
            }

            $message = $payload->withPriority($priority)->toMessage(
                $row instanceof PushActivity ? $this->types->find($row->type_key) : null,
                $row instanceof BroadcastChannel && $row->storage_policy === StoragePolicy::NoStorage,
            );

            $result = $this->send($binding, $message, $destination, $options, $store, $before, $command, $payload, $decision);
            $sentUpTo = max((int) $decision->timestamp, $this->rows->second());
            $store->record($before, $command, $payload, $decision, $result, $sentUpTo);

            return new TransitionResult(
                TransitionOutcome::Sent,
                $result->status,
                $result->reason,
                $result,
                $store->find($id),
                $command->seq,
                $decision->timestamp,
                $priority,
                $decision->downgraded,
                $result->nextAttemptAt,
                $broadcast,
            );
        }

        throw new \RuntimeException('The transition could not be applied: the row kept changing under concurrent writers.');
    }

    /**
     * @template T of Model
     *
     * @param  TransitionStore<T>  $store
     * @param  T  $before
     */
    private function send(BindingRef $binding, LiveActivityMessage $message, Destination $destination, SendOptions $options, TransitionStore $store, Model $before, TransitionCommand $command, TransitionPayload $payload, Transition\TransitionDecision $decision): SendResult
    {
        try {
            $results = iterator_to_array($this->dispatcher->send($binding, $message, [$destination], $options)->results(), false);
        } catch (\Throwable $e) {
            // Globally invalid before the network: undo the claim, then let the caller see the error.
            $store->record($before, $command, $payload, $decision, new SendResult($destination, SendStatus::Skipped, Reason::of(ReasonCode::RequestInvalid)), 0);

            throw $e;
        }

        $result = $results[0] ?? null;

        return $result instanceof SendResult ? $result : new SendResult($destination, SendStatus::Skipped, Reason::of(ReasonCode::None, null, 'The pipeline returned no result.'));
    }

    /**
     * Simulate mode: decide as for real (a closed or locally expired activity, or a stale ticket, is still Skipped),
     * then let the pipeline simulate the request. No ticket, claim, pending transition, counter or expiry is written.
     *
     * @template T of Model
     *
     * @param  TransitionStore<T>  $store
     * @param  T  $row
     */
    private function simulate(TransitionStore $store, Model $row, TransitionCommand $command, TransitionPayload $payload, BindingRef $binding, SendOptions $options, bool $broadcast): TransitionResult
    {
        $now = $this->rows->second();
        $decision = $this->rules->decide($store->snapshot($row), $command, $now);

        if ($decision->kind === DecisionKind::Skip) {
            return new TransitionResult(TransitionOutcome::Skipped, SendStatus::Skipped, $decision->reason, null, $row, $command->seq, broadcast: $broadcast);
        }

        $destination = $store->destination($row);
        if ($destination === null) {
            throw new \LogicException('A deliverable row has no destination.');
        }

        $priority = $decision->priority ?? $payload->priority;
        $message = $payload->withPriority($priority)->toMessage(
            $row instanceof PushActivity ? $this->types->find($row->type_key) : null,
            $row instanceof BroadcastChannel && $row->storage_policy === StoragePolicy::NoStorage,
        );
        $results = iterator_to_array($this->dispatcher->send($binding, $message, [$destination], $options)->results(), false);
        $result = $results[0] ?? new SendResult($destination, SendStatus::Simulated, Reason::of(ReasonCode::Simulated));

        return new TransitionResult(TransitionOutcome::Sent, $result->status, $result->reason, $result, $row, $command->seq,
            $decision->timestamp ?? $now, $priority, $decision->downgraded, null, $broadcast);
    }

    private function bindingFor(Model $row): ?BindingRef
    {
        $name = $row->getAttribute('binding_name');
        $scope = $row->getAttribute('scope');
        if (! is_string($name) || ! is_string($scope)) {
            return null;
        }

        try {
            $binding = $this->dispatcher->binding($name, $scope);
        } catch (ConfigurationException) {
            return null;
        }

        return $binding->identity() === $row->getAttribute('binding_identity') ? $binding : null;
    }

    private function local(TransitionOutcome $outcome, SendStatus $status, ReasonCode $code, string $hint, ?Model $row, bool $broadcast, ?int $seq = null): TransitionResult
    {
        return new TransitionResult($outcome, $status, Reason::of($code, null, $hint), null, $row, $seq, broadcast: $broadcast);
    }

    private static function neverRetryUnknown(?RetryPolicy $policy): RetryPolicy
    {
        $policy ??= new RetryPolicy;

        return new RetryPolicy(
            $policy->maxAttempts,
            $policy->maxElapsedSeconds,
            $policy->backoffBaseMs,
            $policy->backoffMaxMs,
            $policy->jitter,
            UnknownOutcomeRetry::Never,
            $policy->inlineThresholdMs,
        );
    }
}
