<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Exceptions\TransportConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\GuardRecord;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\GuardVerdict;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ClassifyContext;
use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPlanner;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Psr\Clock\ClockInterface;

/**
 * State and steps of ONE Sender::send() call. See Sender for the contract.
 *
 * @internal
 */
final class SendRun
{
    /** Deferral used when a guard denies without an estimate of when to come back. */
    private const DEFAULT_DEFER_SECONDS = 60;

    private readonly BindingRef $binding;

    private readonly string $bindingIdentity;

    private readonly string $credentialIdentity;

    private readonly string $accountKey;

    private readonly bool $hasSecondary;

    private readonly bool $replaceable;

    private readonly RetryPlanner $planner;

    private readonly ?\DateTimeImmutable $pinnedExpiry;

    private readonly int $windowSize;

    private int $sequence = 0;

    private int $prepareAttempts = 0;

    private bool $sentAny = false;

    private bool $yieldedAny = false;

    /** @var array<string, true> bearer generations already refreshed after a rejection in this send */
    private array $refreshed = [];

    /**
     * @param  \Closure(int): void  $sleeper
     * @param  \Closure(\Closure(SendObserver): void): void  $notify
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly Message $message,
        private readonly SendOptions $options,
        private readonly Transport $transport,
        private readonly Guards $guards,
        private readonly SafeModeGuard $safeMode,
        private readonly ClockInterface $clock,
        RetryPolicy $policy,
        private readonly Redactor $redactor,
        private readonly \Closure $sleeper,
        private readonly \Closure $notify,
        private readonly bool $observed = true,
    ) {
        $this->binding = $driver->binding();
        $this->bindingIdentity = $this->binding->identity();
        $this->credentialIdentity = $driver->credentialIdentity();
        $this->accountKey = $driver->accountKey();
        $this->hasSecondary = $driver->hasSecondaryCredential();
        $this->replaceable = $driver->isReplaceable($message);
        $this->planner = new RetryPlanner($policy);
        $this->windowSize = $options->window ?? max(1, $driver->window());
        $this->pinnedExpiry = $options->expiresAt
            ?? ($message instanceof PushMessage ? $message->resolveExpiry($clock->now()) : null);
    }

    /**
     * @param  iterable<Destination|SendItem>  $destinations
     * @return \Generator<int, SendResult>
     */
    public function results(iterable $destinations): \Generator
    {
        $window = [];
        foreach ($destinations as $value) {
            $window[] = SendItem::of($value);
            if (count($window) >= $this->windowSize) {
                foreach ($this->window($window) as $result) {
                    $this->yieldedAny = true;
                    yield $result;
                }
                $window = [];
            }
        }

        if ($window !== []) {
            foreach ($this->window($window) as $result) {
                $this->yieldedAny = true;
                yield $result;
            }
        }
    }

    /**
     * @param  list<SendItem>  $items
     * @return list<SendResult> in input order
     */
    private function window(array $items): array
    {
        /** @var array<int, SendResult> $results */
        $results = [];
        $now = $this->clock->now();
        $mode = $this->options->effectiveMode;

        /** @var list<WorkItem> $live */
        $live = [];
        foreach ($items as $position => $item) {
            $work = $this->workItem($position, $item, $now);

            $stop = $this->planner->beforeAttempt($work->budget, $now);
            if ($stop !== null) {
                $this->emit($work, $stop->status, $stop->reason, null, null, $results);

                continue;
            }

            $operation = $this->driver->operationKind($this->message, $item->destination, $this->options->validateOnly);
            $safe = $this->safeMode->decide($mode, $this->options->applicationEnvironment ?? '', $this->binding, $item->destination, $operation, $this->options->live);

            if ($safe->isSimulated()) {
                // Compile anyway (no external effect): an invalid message is reported exactly as it would be live.
                if ($this->prepare($work, $now, false, $results) !== null) {
                    $this->emit($work, SendStatus::Simulated, $safe->reason(), null, null, $results);
                }

                continue;
            }
            if (! $safe->allowed) {
                $this->emit($work, SendStatus::Skipped, $safe->reason(), null, null, $results);

                continue;
            }

            $live[] = $work;
        }

        $pending = $this->applyGates($live, $now, $results);

        $passes = 0;
        $maxPasses = $this->planner->policy()->maxAttempts + 3;
        while ($pending !== []) {
            if (++$passes > $maxPasses) {
                // Defensive: the planner bounds every loop; should it not, nothing is lost.
                foreach ($pending as $work) {
                    $this->emit($work, $work->budget->unknownOutcomeSeen ? SendStatus::OutcomeUnknown : SendStatus::Failed,
                        Reason::of(ReasonCode::RetryBudgetExhausted, null, 'The in-process retry loop was stopped.'), $work->lastDecision, null, $results);
                }

                break;
            }
            $pending = $this->pass($pending, $results);
        }

        ksort($results);

        return array_values($results);
    }

    private function workItem(int $position, SendItem $item, \DateTimeImmutable $now): WorkItem
    {
        $expiresAt = $this->pinnedExpiry ?? $item->budget?->expiresAt;
        $budget = $item->budget ?? RetryBudget::start($this->planner->policy(), $now, $expiresAt);
        $messageId = $item->providerMessageId
            ?? ($this->options->effectKey !== null
                ? MessageIds::forEffect($this->bindingIdentity, $this->options->effectKey, $item->destination)
                : MessageIds::random());

        return new WorkItem($position, $item, $budget, $messageId, $expiresAt);
    }

    /**
     * @param  list<WorkItem>  $live
     * @param  array<int, SendResult>  $results
     * @return list<WorkItem>
     */
    private function applyGates(array $live, \DateTimeImmutable $now, array &$results): array
    {
        foreach ($this->options->gates as $gate) {
            if ($live === []) {
                return [];
            }
            $context = new GateContext($this->binding, $this->message, $this->options->trafficClass, $now, $this->options->effectKey);

            try {
                $denials = $gate->check($context, array_map(static fn (WorkItem $w): SendItem => $w->item, $live));
            } catch (\Throwable $e) {
                if ($this->nothingHappenedYet()) {
                    throw $e;
                }
                foreach ($live as $work) {
                    $this->emit($work, SendStatus::Deferred, Reason::of(ReasonCode::PolicyIneligible, null, sprintf(
                        'The destination gate %s failed (%s): the destination was not sent and can be attempted again.', $gate::class, $e::class,
                    )), null, $now->modify('+'.self::DEFAULT_DEFER_SECONDS.' seconds'), $results);
                }

                return [];
            }

            $kept = [];
            foreach ($live as $i => $work) {
                $denial = $denials[$i] ?? null;
                if ($denial === null) {
                    $kept[] = $work;

                    continue;
                }
                $this->emit($work, $denial->status, $denial->reason, null, $denial->retryAt, $results);
            }
            $live = $kept;
        }

        return $live;
    }

    /**
     * One attempt for the pending destinations: guards, bearer, compile, transport, classification, planning.
     *
     * @param  list<WorkItem>  $pending
     * @param  array<int, SendResult>  $results
     * @return list<WorkItem> destinations to attempt again in-process
     */
    private function pass(array $pending, array &$results): array
    {
        $now = $this->clock->now();

        $ready = [];
        foreach ($pending as $work) {
            $stop = $this->planner->beforeAttempt($work->budget, $now, $work->lastCause);
            if ($stop !== null) {
                $this->emit($work, $stop->status, $stop->reason, $work->lastDecision, null, $results);

                continue;
            }
            $ready[] = $work;
        }
        if ($ready === []) {
            return [];
        }

        $verdict = $this->guardsBefore(count($ready), $ready, $now, $results);
        if ($verdict === null) {
            return [];
        }
        $generation = $this->hasSecondary ? $verdict->credentialGeneration : 0;
        $allowed = array_slice($ready, 0, $verdict->allowed);
        foreach (array_slice($ready, $verdict->allowed) as $work) {
            $this->deny($work, $verdict, $now, $results);
        }
        if ($allowed === []) {
            return [];
        }

        // Bearer: once per pass, from the generation the authentication breaker selected.
        $provider = $this->driver->tokenProvider($generation);
        $token = null;
        if ($provider !== null) {
            try {
                $token = $provider->token();
            } catch (\Throwable $e) {
                return $this->tokenUnavailable($allowed, $generation, $e, $results);
            }
        }

        // Compile and build the requests, grouped by connection pool.
        /** @var array<string, array{profile: ConnectionProfile, requests: list<Request>}> $groups */
        $groups = [];
        /** @var array<string, WorkItem> $byId */
        $byId = [];
        foreach ($allowed as $work) {
            $work->generation = $generation;
            $compiled = $this->prepare($work, $now, true, $results);
            if ($compiled === null) {
                continue;
            }
            try {
                $profile = $this->driver->connectionProfile($this->driver->endpointRole($compiled), $generation);
                $request = $this->driver->buildRequest('r'.(++$this->sequence), $compiled, $work->destination(), $token);
            } catch (\Throwable $e) {
                $this->prepareFailed($work, $e, false, $results);

                continue;
            }
            $work->request = $request;
            $work->token = $token;
            $work->poolKey = $profile->poolKey;
            $byId[$request->id] = $work;
            $groups[$profile->poolKey] ??= ['profile' => $profile, 'requests' => []];
            $groups[$profile->poolKey]['requests'][] = $request;
        }
        if ($byId === []) {
            return [];
        }

        $exchanges = $this->exchange($groups, $byId);

        // Classify everything first, feed the guards, then plan: a failover decided by any answer of this pass
        // applies to every destination of the pass.
        $classifier = $this->driver->classifier();
        /** @var list<array{0: WorkItem, 1: Decision}> $classified */
        $classified = [];
        $successes = null;
        $successCount = 0;
        $failedOver = false;
        foreach ($byId as $id => $work) {
            $exchange = $exchanges[$id];
            $work->sent = true;
            $decision = $classifier->classify($exchange, new ClassifyContext($work->destination(), $this->binding, $this->options->validateOnly));
            $classified[] = [$work, $decision];

            if (self::isPlainSuccess($decision)) {
                $successes ??= $decision;
                $successCount++;
            } else {
                $failedOver = $this->feed($decision, $generation) || $failedOver;
            }

            if ($this->observed) {
                $this->attemptFinished($work, $exchange, $decision, $token);
            }
        }
        if ($successes !== null) {
            $failedOver = $this->feed($successes, $generation, $successCount) || $failedOver;
        }

        $this->refreshRejectedTokens($classified, $provider, $generation);

        // Plan.
        $now = $this->clock->now();
        $retry = [];
        $wait = 0;
        foreach ($classified as [$work, $decision]) {
            $plan = $this->planner->plan($decision, $work->budget, $this->replaceable, $now);
            $work->budget = $plan->budget;
            $work->lastCause = $plan->cause();
            $work->lastDecision = $decision;

            if ($decision->credentialAction === CredentialAction::ReportAuthFailure && $failedOver && $generation === 0
                && ! $work->failoverUsed && $this->planner->hasAttemptLeft($work->budget, $now)) {
                $work->failoverUsed = true;   // at most one extra attempt per destination, in the same budget
                $retry[] = $work;

                continue;
            }

            if ($plan->status === SendStatus::Deferred && $plan->inline) {
                $retry[] = $work;
                $wait = max($wait, $plan->delayMs ?? 0);

                continue;
            }

            $this->emit($work, $plan->status, $plan->reason, $decision, $plan->nextAttemptAt, $results);
        }

        if ($retry !== [] && $wait > 0) {
            ($this->sleeper)(min($wait, $this->planner->policy()->inlineThresholdMs));
        }

        return $retry;
    }

    /**
     * @param  list<WorkItem>  $ready
     * @param  array<int, SendResult>  $results
     */
    private function guardsBefore(int $n, array $ready, \DateTimeImmutable $now, array &$results): ?GuardVerdict
    {
        try {
            $verdict = $this->guards->before($this->bindingIdentity, $this->credentialIdentity, $this->accountKey, $this->options->trafficClass, $n);
        } catch (\Throwable $e) {
            if ($this->nothingHappenedYet()) {
                throw $e;
            }
            foreach ($ready as $work) {
                $this->emit($work, SendStatus::Deferred, Reason::of(ReasonCode::ProviderUnavailable, null, sprintf(
                    'The send guards could not be consulted (%s): the destination was not sent and can be attempted again.', $e::class,
                )), $work->lastDecision, $now->modify('+'.self::DEFAULT_DEFER_SECONDS.' seconds'), $results);
            }

            return null;
        }

        foreach ($verdict->transitions as $transition) {
            $this->transition(GuardKind::PreSendCheck, $transition, $verdict->credentialGeneration);
        }

        return $verdict;
    }

    /** @param  array<int, SendResult>  $results */
    private function deny(WorkItem $work, GuardVerdict $verdict, \DateTimeImmutable $now, array &$results): void
    {
        if ($verdict->skipReason === ReasonCode::CircuitOpen) {
            $this->emit($work, SendStatus::Skipped, Reason::of(ReasonCode::CircuitOpen, null,
                'A circuit breaker is open for this credential or app (repeated authentication failures or routing errors): nothing was sent. Check the credential; sends resume after the cool-down probe succeeds.'),
                $work->lastDecision, null, $results);

            return;
        }

        $at = $verdict->retryAt !== null && $verdict->retryAt > $now ? $verdict->retryAt : $now->modify('+'.self::DEFAULT_DEFER_SECONDS.' seconds');
        $this->emit($work, SendStatus::Deferred, Reason::of($verdict->skipReason ?? ReasonCode::QuotaExceeded, null,
            'The provider account is throttled or the send rate limit is reached: nothing was sent now; attempt again at nextAttemptAt.'),
            $work->lastDecision, $at, $results);
    }

    /**
     * @param  list<WorkItem>  $allowed
     * @param  array<int, SendResult>  $results
     * @return list<WorkItem>
     */
    private function tokenUnavailable(array $allowed, int $generation, \Throwable $e, array &$results): array
    {
        $reason = Reason::of(ReasonCode::AuthTokenInvalid, null, sprintf(
            'The bearer of credential generation %d could not be obtained (%s: %s). Nothing was sent.',
            $generation, $e::class, $this->redactor->providerMessage($e->getMessage()),
        ));
        $failedOver = $this->feed(new Decision(SendStatus::Failed, $reason, credentialAction: CredentialAction::ReportAuthFailure), $generation);

        $retry = [];
        foreach ($allowed as $work) {
            if ($failedOver && $generation === 0 && ! $work->failoverUsed) {
                $work->failoverUsed = true;   // no attempt was made: the secondary gets this one
                $retry[] = $work;

                continue;
            }
            $this->emit($work, $work->budget->unknownOutcomeSeen ? SendStatus::OutcomeUnknown : SendStatus::Failed, $reason, $work->lastDecision, null, $results);
        }

        return $retry;
    }

    /**
     * Compile one destination. Returns null when the destination got its (Rejected / Failed) result instead.
     *
     * @param  array<int, SendResult>  $results
     */
    private function prepare(WorkItem $work, \DateTimeImmutable $now, bool $live, array &$results): ?CompiledMessage
    {
        $first = ++$this->prepareAttempts === 1;

        try {
            if ($live && ! $work->markerResolved) {
                $work->markerResolved = true;
                $work->receiptMarker = $this->options->receiptMarker === null ? null : ($this->options->receiptMarker)($work->destination(), $work->messageId);
            }

            $compiled = $this->driver->compiler()->compile($this->message, $work->destination(), new CompileContext(
                $this->binding,
                $now,
                $work->expiresAt,
                $this->options->validateOnly,
                $work->messageId,
                $live ? $work->receiptMarker : null,
            ));
        } catch (\Throwable $e) {
            $this->prepareFailed($work, $e, $first, $results);

            return null;
        }

        $work->compiled = $compiled;
        $work->messageIdOnWire = in_array($work->messageId, $compiled->headers, true);

        return $compiled;
    }

    /**
     * A destination could not be prepared. A message-level error on the very first destination, before anything was
     * sent or reported, is a globally invalid input and propagates; anything else is that destination's result.
     *
     * @param  array<int, SendResult>  $results
     */
    private function prepareFailed(WorkItem $work, \Throwable $e, bool $first, array &$results): void
    {
        $destinationLevel = $e instanceof InvalidDestinationException || $e instanceof PayloadTooLargeException;
        if ($first && ! $destinationLevel && $this->nothingHappenedYet()) {
            throw $e;
        }

        $hint = $this->redactor->string($e->getMessage());
        if ($e instanceof ValidationException) {
            $code = match (true) {
                $e instanceof PayloadTooLargeException => ReasonCode::PayloadTooLarge,
                $e instanceof InvalidDestinationException => ReasonCode::RequestInvalid,
                default => ReasonCode::PayloadInvalid,
            };
            $this->emit($work, SendStatus::Rejected, Reason::of($code, null, $hint), null, null, $results);

            return;
        }

        // Not a validation error (a receipt-marker callback, a driver bug): nothing was sent for this destination.
        $this->emit($work, $work->budget->unknownOutcomeSeen ? SendStatus::OutcomeUnknown : SendStatus::Failed,
            Reason::of(ReasonCode::RequestInvalid, null, sprintf('The request could not be prepared (%s: %s). Nothing was sent.', $e::class, $hint)),
            $work->lastDecision, null, $results);
    }

    /**
     * One multiplexed transport call per pool. Every request gets an exchange: a missing one is an unknown outcome
     * (or, after a transport configuration error, which is raised before anything is written, a proven not-sent).
     *
     * @param  array<string, array{profile: ConnectionProfile, requests: list<Request>}>  $groups
     * @param  array<string, WorkItem>  $byId
     * @return array<string, Exchange>
     */
    private function exchange(array $groups, array $byId): array
    {
        $exchanges = [];
        foreach ($groups as $group) {
            $error = null;
            try {
                foreach ($this->transport->send($group['requests'], $group['profile']) as $exchange) {
                    $exchanges[$exchange->request->id] = $exchange;
                    $this->sentAny = true;
                }
            } catch (\Throwable $e) {
                if ($e instanceof TransportConfigurationException && $this->nothingHappenedYet() && $exchanges === []) {
                    throw $e;
                }
                $error = $e;
            }
            $this->sentAny = true;

            foreach ($group['requests'] as $request) {
                if (isset($exchanges[$request->id])) {
                    continue;
                }
                $exchanges[$request->id] = Exchange::failed($request, $error instanceof TransportConfigurationException
                    ? new TransportFailure(TransportEvidence::NotSent, 0, 'The transport refused the batch before sending: '.$error::class, false)
                    : new TransportFailure(TransportEvidence::Unknown, 0, 'The transport returned no result for this request'.($error !== null ? ' ('.$error::class.')' : '').'.'));
            }
        }

        return $exchanges;
    }

    /**
     * One refresh per rejected bearer, however many in-flight requests were rejected with it.
     *
     * @param  list<array{0: WorkItem, 1: Decision}>  $classified
     */
    private function refreshRejectedTokens(array $classified, ?AccessTokenProvider $provider, int $generation): void
    {
        if ($provider === null) {
            return;
        }
        foreach ($classified as [$work, $decision]) {
            $token = $work->token;
            if ($decision->credentialAction !== CredentialAction::RefreshToken || $token === null) {
                continue;
            }
            $key = $generation.':'.$token->generation();
            if (isset($this->refreshed[$key])) {
                continue;
            }
            $this->refreshed[$key] = true;
            try {
                $provider->refreshAfterRejection($token);
            } catch (\Throwable) {
                // The retry asks for a token again; if none can be obtained the destinations fail without a send.
                continue;
            }
            $identity = $this->driver->credentialIdentityFor($generation);
            ($this->notify)(fn (SendObserver $o) => $o->tokenRefreshed($this->binding, $generation, $identity));
        }
    }

    /** Feeds the guards; returns true when the authentication breaker failed over to the secondary credential. */
    private function feed(Decision $decision, int $generation, int $count = 1): bool
    {
        try {
            $record = $this->guards->record($decision, $this->bindingIdentity, $this->credentialIdentity, $this->accountKey, $generation, $this->hasSecondary, $count);
        } catch (\Throwable) {
            return false;   // guard state is advisory: losing one sample never changes a result
        }

        $this->onRecord($record, $generation);

        return $record->auth === BreakerTransition::FailedOver;
    }

    private function onRecord(GuardRecord $record, int $generation): void
    {
        if ($record->auth !== BreakerTransition::None) {
            $this->transition(GuardKind::Auth, $record->auth, $generation);
        }
        if ($record->destination !== BreakerTransition::None) {
            $this->transition(GuardKind::DestinationQuality, $record->destination, $generation);
        }
        $until = $record->throttledUntil;
        if ($until !== null) {
            ($this->notify)(fn (SendObserver $o) => $o->providerThrottled($this->binding, $this->accountKey, $until));
        }
    }

    private function transition(GuardKind $kind, BreakerTransition $transition, int $generation): void
    {
        $event = new GuardTransition($this->binding, $kind, $transition, $generation);
        ($this->notify)(static fn (SendObserver $o) => $o->guardTransition($event));
    }

    private function attemptFinished(WorkItem $work, Exchange $exchange, Decision $decision, ?AuthToken $token): void
    {
        $redactor = $token === null ? $this->redactor : $this->redactor->withSecrets([$token->value]);
        if ($work->receiptMarker !== null) {
            $redactor = $redactor->withSecrets([$work->receiptMarker]);
        }
        $failure = $exchange->failure;

        $report = new AttemptReport(
            $this->binding,
            $work->item,
            $work->budget->attempts + 1,
            $work->generation,
            $work->poolKey,
            $decision,
            $redactor->request($exchange->request),
            $exchange->response === null ? null : $redactor->response($exchange->response),
            $failure === null ? null : [
                'evidence' => $failure->evidence->value,
                'errno' => $failure->errno,
                'message' => $redactor->providerMessage($failure->message),
                'request_written' => $failure->requestWritten,
            ],
            $exchange->durationMs,
        );
        ($this->notify)(static fn (SendObserver $o) => $o->attemptFinished($report));
    }

    /** @param  array<int, SendResult>  $results */
    private function emit(WorkItem $work, SendStatus $status, Reason $reason, ?Decision $decision, ?\DateTimeImmutable $nextAttemptAt, array &$results): void
    {
        $meta = ['message_id' => $work->messageId];
        if ($work->sent) {
            $meta['credential_generation'] = (string) $work->generation;
        }
        if ($work->compiled !== null && $work->compiled->warnings !== []) {
            $meta['warnings'] = implode(' | ', $work->compiled->warnings);
        }
        if ($decision !== null) {
            $meta = array_replace($meta, $decision->meta);
        }

        $result = new SendResult(
            $work->destination(),
            $status,
            $reason,
            $work->budget->attempts,
            $decision?->providerMessageId ?? ($work->sent && $work->messageIdOnWire ? $work->messageId : null),
            $decision?->httpStatus,
            $status === SendStatus::Deferred ? $nextAttemptAt : null,
            $decision === null ? DestinationAction::None : $decision->destinationAction,
            $decision?->invalidatedAtMs,
            $work->item->deliveryId,
            $meta,
        );
        $results[$work->position] = $result;

        $budget = $work->budget;
        $item = $work->item;
        ($this->notify)(static fn (SendObserver $o) => $o->resultProduced($result, $budget, $item));
    }

    private function nothingHappenedYet(): bool
    {
        return ! $this->sentAny && ! $this->yieldedAny;
    }

    private static function isPlainSuccess(Decision $decision): bool
    {
        return in_array($decision->status, [SendStatus::Accepted, SendStatus::Validated], true)
            && $decision->credentialAction === CredentialAction::None
            && $decision->destinationAction === DestinationAction::None
            && ! $decision->throttleProvider;
    }
}
