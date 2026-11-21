<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\MessageIds;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Ledger\Effect;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Core\Ledger\RecordedDestination;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptMarkerIssuer;
use Psr\Clock\ClockInterface;

/**
 * Durable profile: the relay loop of the outbox (docs/contracts/ledger.md, worker protocol of DeliveryStore).
 *
 * One run, under a lease owner unique to the run:
 *  1. claimDue() a chunk (scoped; optionally one effect); stop when nothing is claimed or the chunk deadline is near;
 *  2. per effect: load the effect and its message snapshot; a missing effect or an erased snapshot is recorded as
 *     Skipped(Cancelled) and nothing is sent; a connection that no longer resolves is Skipped(BindingMismatch);
 *  3. registry devices are resolved in one query per chunk; an ineligible device is Skipped(PolicyIneligible);
 *  4. markAttemptStarted() for exactly the requests the Sender is about to hand to the transport (RelayRecorder is a
 *     TransmitObserver; one statement per pass); a request whose lease was lost is withheld, not sent;
 *  5. PushDispatcher::send() with the default options of the binding plus the effect's traffic class, effect key,
 *     absolute expiry, the receipt marker callback (when the receipts module is present) and a per-call
 *     observer that records every result with its final budget as it arrives;
 *  6. the report is consumed fully and every write is flushed before the run returns (i.e. before the job is acked).
 *
 * Deferred deliveries are only recorded with their next attempt: nothing sleeps, nothing is redispatched per
 * delivery; one follow-up relay per effect is scheduled for the earliest next attempt (RelayFollowUps). No SQL
 * transaction is open while the Sender is on the network (the ledger's writes are single statements).
 */
final readonly class DeliveryRelay
{
    public function __construct(
        private Ledger $ledger,
        private PushDispatcher $dispatcher,
        private RegistryResolver $resolver,
        private RelayFollowUps $followUps,
        private ClockInterface $clock,
        private QueueSettings $settings,
        /** Receipts module: issues the `mp_r` marker per individual destination (null when the module is absent). */
        private ?ReceiptMarkerIssuer $receipts = null,
        /** The binding-mismatch event and log records (null: only the ledger records the skip). */
        private ?BindingMismatches $mismatches = null,
    ) {}

    public function run(string $scope, ?string $effectId = null, ?string $owner = null): RelayReport
    {
        $owner ??= WorkerId::generate();
        $recorder = new RelayRecorder($this->ledger);
        $deadline = LedgerTime::ms($this->clock->now()) + $this->settings->chunkDeadline * 1000;
        $slowest = 0;
        $claimedTotal = $handed = $lost = 0;
        $deadlineReached = false;
        /** @var array<string, Effect|null> $effects */
        $effects = [];
        /** @var array<string, Reason> $mismatched effect id => binding-mismatch reason (event dispatched once per run) */
        $mismatched = [];

        while (true) {
            $now = LedgerTime::ms($this->clock->now());
            $left = $deadline - $now;
            if ($left <= 0 || ($slowest > 0 && $left < $slowest)) {
                $deadlineReached = true;

                break;
            }

            $claimed = $this->ledger->claimDue($scope, $owner, $this->settings->chunk, $effectId);
            if ($claimed === []) {
                break;
            }
            $claimedTotal += count($claimed);

            $byEffect = [];
            foreach ($claimed as $c) {
                $byEffect[$c->effectId()][] = $c;
            }
            foreach ($byEffect as $id => $group) {
                $id = (string) $id;
                $effects[$id] ??= $this->ledger->effect($scope, $id);
                [$h, $l] = $this->relayEffect($scope, $effects[$id], $group, $recorder, $mismatched);
                $handed += $h;
                $lost += $l;
                if ($recorder->hasFailures()) {
                    break;
                }
            }

            // Every outcome of the chunk is stored before the next claim (and before the job is acknowledged).
            $recorder->flush();
            $recorder->release();
            $slowest = max($slowest, LedgerTime::ms($this->clock->now()) - $now);
        }

        $followUps = [];
        foreach ($recorder->deferredUntil as $id => $at) {
            $effect = $effects[$id] ?? null;
            if ($this->followUps->schedule($scope, $id, $at, $effect === null ? null : $this->settings->queueFor($effect->trafficClass))) {
                $followUps[] = $id;
            }
        }
        if ($deadlineReached) {
            // Work may be left: hand over to a fresh run now rather than wait for the sweeper.
            $this->followUps->schedule($scope, $effectId, $this->clock->now());
        }

        return new RelayReport($claimedTotal, $handed, $lost, $recorder->byStatus, $recorder->ignored, $followUps, $deadlineReached, array_map('strval', array_keys($effects)));
    }

    /**
     * @param  list<ClaimedDelivery>  $claimed  deliveries of one effect
     * @param  array<string, Reason>  $mismatched
     * @return array{0: int, 1: int} [handed over, leases lost]
     */
    private function relayEffect(string $scope, ?Effect $effect, array $claimed, RelayRecorder $recorder, array &$mismatched): array
    {
        $message = $effect === null || ! $effect->hasMessage ? null : $this->message($scope, $effect->id);
        if ($effect === null || $message === null) {
            $this->skipAll($claimed, Reason::of(ReasonCode::Cancelled, null, 'The effect or its message snapshot no longer exists (cancelled or forgotten): nothing was sent.'), $recorder);

            return [0, 0];
        }

        try {
            $options = $this->dispatcher->defaultOptions($effect->binding);
        } catch (UnknownConnectionException $e) {
            // Same rule as a direct send: Skipped(BindingMismatch) for every destination, no attempt, the event once
            // per run (later chunks of the same effect in this run reuse the reason).
            $reason = $mismatched[$effect->id] ??= $this->mismatches?->detected($this->dispatcher, $effect->binding, $e)
                ?? Reason::of(ReasonCode::BindingMismatch, null, 'The connection of this effect no longer resolves: nothing was sent, no fallback to another connection.');
            $results = array_map(static fn (ClaimedDelivery $c): SendResult => self::result($c, SendStatus::Skipped, $reason), $claimed);
            foreach ($claimed as $i => $c) {
                $recorder->expect($c->id(), $c->effectId(), $c->lease);
                $recorder->save($c->id(), $results[$i]);
            }
            $this->mismatches?->log($effect->binding, $results, ['operation' => $effect->operation->value, 'traffic_class' => $effect->trafficClass->value, 'effect_id' => $effect->id]);

            return [0, 0];
        }

        $options = $options
            ->withTrafficClass($effect->trafficClass)
            ->withEffectKey($effect->effectKey)
            ->withEffectId($effect->id)
            ->withExpiresAt($effect->expiresAt)
            ->withValidateOnly($effect->operation === OperationKind::Validate)
            ->withObserver($recorder);
        if ($this->receipts !== null) {
            // The issuer itself returns null when receipts are off or the destination is not one installation.
            $options = $options->withReceiptMarker($this->receipts->callback($scope));
        }

        [$toSend, $toSkip] = $this->resolver->resolve($effect->binding, $claimed);
        foreach ($toSkip as [$c, $reason]) {
            $this->skip($c, $reason, $recorder);
        }

        $items = function () use ($toSend, $recorder): \Generator {
            // The attempt is marked started by the recorder (a TransmitObserver) when the Sender is about to hand the
            // requests to the transport: an item still waiting in the Sender's window when the worker dies stays
            // leased, i.e. simply due again after the lease, with no attempt counted and no unknown outcome.
            foreach ($toSend as [$c, $destination]) {
                if ($recorder->hasFailures()) {
                    return; // the ledger is not taking writes: leave the rest leased, not started (requeued on expiry)
                }
                $recorder->expect($c->id(), $c->effectId(), $c->lease);

                yield new SendItem($destination, $c->budget(), $c->id(), self::resumedMessageId($c));
            }
        };
        $startedBefore = $recorder->started;
        $lostBefore = $recorder->lost;

        try {
            $report = $this->dispatcher->send($effect->binding, $message, $items(), $options);
            foreach ($report->results() as $result) {
                // Results the observer did not see (a dispatcher that answered without the pipeline) are stored here.
                if ($result->deliveryId !== null && ! $recorder->handled($result->deliveryId)) {
                    $recorder->save($result->deliveryId, $result);
                }
            }
        } catch (ValidationException $e) {
            // Invalid for every destination, detected before anything was sent: a definitive refusal, recorded.
            $reason = Reason::of(ReasonCode::PayloadInvalid, null, 'The stored message is invalid ('.$e::class.'); nothing was sent.');
            foreach ($toSend as [$c]) {
                if (! $recorder->handled($c->id())) {
                    $recorder->expect($c->id(), $c->effectId(), $c->lease);
                    $recorder->save($c->id(), self::result($c, SendStatus::Rejected, $reason));
                }
            }
        }

        return [$recorder->started - $startedBefore, $recorder->lost - $lostBefore];
    }

    private function message(string $scope, string $effectId): ?Message
    {
        try {
            return $this->ledger->message($scope, $effectId);
        } catch (PrivacyException) {
            return null;
        }
    }

    /** @param  list<ClaimedDelivery>  $claimed */
    private function skipAll(array $claimed, Reason $reason, RelayRecorder $recorder): void
    {
        foreach ($claimed as $c) {
            $this->skip($c, $reason, $recorder);
        }
    }

    private function skip(ClaimedDelivery $c, Reason $reason, RelayRecorder $recorder): void
    {
        $recorder->expect($c->id(), $c->effectId(), $c->lease);
        $recorder->save($c->id(), self::result($c, SendStatus::Skipped, $reason));
    }

    private static function result(ClaimedDelivery $c, SendStatus $status, Reason $reason): SendResult
    {
        $d = $c->delivery;

        return new SendResult(
            $c->destination ?? new RecordedDestination($d->kind, $d->logicalKey, $d->deviceRef),
            $status,
            $reason,
            $d->attempts,
            $d->providerMessageId,
            deliveryId: $d->id,
        );
    }

    /**
     * The provider message id of the earlier attempts, so APNs sees the same apns-id again. The ledger keeps the
     * provider's own id (APNs answers it in upper case; an FCM message name is not a UUID): only a canonical UUID
     * can be resumed, anything else is left to the effect key (deterministic ids).
     */
    private static function resumedMessageId(ClaimedDelivery $c): ?string
    {
        $id = $c->providerMessageId();
        if ($id === null) {
            return null;
        }
        $id = strtolower($id);

        return MessageIds::isCanonical($id) ? $id : null;
    }
}
