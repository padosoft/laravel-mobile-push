<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Light profile (best effort): one encrypted job per small chunk of destinations.
 *
 * Payload: the binding reference (never a secret), the serialized message, the typed destinations (kind + value +
 * registry reference), the essential options (traffic class, effect key, validate-only, the absolute expiry pinned
 * at dispatch time) and, for a continuation, each destination's retry budget. The job carries raw tokens, so it is
 * ShouldBeEncrypted.
 *
 * handle() sends through PushDispatcher, consumes the whole report, and dispatches a NEW job carrying only the
 * Deferred destinations with their budgets, delayed to their next attempt. The job is never released (a released
 * job is the same payload again) and never retried by the queue (tries = 1): Accepted, Rejected, Failed, Expired,
 * Skipped and OutcomeUnknown are terminal here.
 *
 * Chunk deadline (queue.chunk_deadline, as in the durable relay; see ChunkDeadline): once it has passed, or the time
 * left is shorter than the slowest window of this job, the Sender is given no further destination. The window in
 * flight is never cut and never handed over (its outcomes are whatever the transport reports, unknown included).
 * The destinations never given to the Sender go, with their budgets unchanged, to ONE new job dispatched at once
 * (the next continuation number) with a fresh deadline. Each job sends at least one destination.
 *
 * What this profile does NOT promise: if the worker dies while a job is sending, the job is not retried, so the
 * destinations not reached yet are OMITTED; if the queue redelivers a job whose worker died (e.g. retry_after
 * passed while it was still sending, or a backend that ignores tries), destinations already sent are sent AGAIN.
 * Deduplication is in-process only. Use the durable profile when that matters.
 */
final class SendPushJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Never retried by the queue: a retry would re-send what was already sent. */
    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    /**
     * Unix second at which this job was built (dispatch time; a continuation gets its own). Null = unknown: a job
     * serialized before this field existed unserializes with the default, and no queue wait is recorded for it.
     */
    public ?int $queuedAt = null;

    /**
     * @param  array{scope: string, name: string, provider: string, app_id: string, environment: string, generation: string}  $binding
     * @param  list<array<string, mixed>>  $destinations  QueuedDestination::toArray() entries
     */
    public function __construct(
        public readonly array $binding,
        public readonly string $message,
        public readonly array $destinations,
        public readonly string $effectId,
        public readonly string $effectKey,
        public readonly string $trafficClass = TrafficClass::Transactional->value,
        public readonly bool $validateOnly = false,
        /** Absolute logical expiry pinned at dispatch time (Unix ms), null = none. */
        public readonly ?int $expiresAtMs = null,
        /** 0 for the first job of a send, n for the n-th continuation. */
        public readonly int $continuation = 0,
        int $timeout = 120,
        ?int $queuedAt = null,
    ) {
        $this->timeout = $timeout;
        $this->queuedAt = $queuedAt;
    }

    public function handle(PushDispatcher $dispatcher, SendJobDispatcher $jobs, ClockInterface $clock, QueueSettings $settings, LoggerInterface $logger, ?BindingMismatches $mismatches = null, ?MetricsRecorder $metrics = null): void
    {
        $binding = BindingRef::fromArray($this->binding);
        if ($this->queuedAt !== null) {
            // How long the job waited in the queue backend; recording never throws and never delays the send.
            $metrics?->recordQueueWait($binding, max(0, $clock->now()->getTimestamp() - $this->queuedAt));
        }
        $message = unserialize($this->message);
        if (! $message instanceof Message) {
            $logger->warning('mobile-push: a queued send carried no readable message; nothing was sent.', $this->context());

            return;
        }

        try {
            $options = $dispatcher->defaultOptions($binding);
        } catch (UnknownConnectionException $e) {
            // The binding rule of a direct send: every destination Skipped(BindingMismatch), no attempt, the event once
            // per job run, the results in the log sinks when they are enabled; the job ends cleanly (no retry).
            $logger->warning('mobile-push: the connection of a queued send no longer resolves to its binding; nothing was sent.', $this->context());
            if ($mismatches !== null) {
                $reason = $mismatches->detected($dispatcher, $binding, $e);
                $mismatches->log($binding, (function () use ($reason): \Generator {
                    foreach ($this->items() as $item) {
                        yield new SendResult($item->destination, SendStatus::Skipped, $reason, 0, deliveryId: $item->deliveryId);
                    }
                })(), ['operation' => $this->validateOnly ? 'validate' : 'send', 'traffic_class' => $this->trafficClass, 'effect_id' => $this->effectId]);
            }

            return;
        }

        $deferred = new DeferredCollector;
        $options = $options
            ->withTrafficClass(TrafficClass::tryFrom($this->trafficClass) ?? TrafficClass::Transactional)
            ->withEffectKey($this->effectKey)
            ->withEffectId($this->effectId)
            ->withValidateOnly($this->validateOnly)
            ->withExpiresAt($this->expiresAtMs === null ? null : LedgerTime::date($this->expiresAtMs))
            ->withObserver($deferred);

        // The chunk deadline (queue.chunk_deadline, the setting the durable relay enforces) starts now. Past it the
        // Sender is given no further destination; what it never received is handed to ONE follow-up job below.
        $deadline = new ChunkDeadline($clock, $settings->chunkDeadline);

        try {
            $report = $dispatcher->send($binding, $message, $deadline->items($this->destinations), $options);
            foreach ($report->results() as $result) {
                // consume the whole cursor: the send happens while it is read
            }
        } catch (ValidationException $e) {
            $logger->warning('mobile-push: a queued message is invalid for every destination; nothing was sent.', $this->context() + ['exception' => $e::class]);

            return;
        }

        $rest = $deadline->remaining($this->destinations);
        if ($rest !== []) {
            // Never compiled, never written: they keep their budgets and run at once in a fresh job (fresh deadline).
            $logger->info('mobile-push: a light-profile job reached its chunk deadline; the destinations not attempted go to a follow-up job.', $this->context() + [
                'handed_over' => count($rest),
                'chunk_deadline' => $settings->chunkDeadline,
            ]);
            $jobs->dispatchNow($this->continue($rest, $clock->now()->getTimestamp()), $this->connection, $this->queue);
        }

        $planner = new FollowUpPlanner($settings->followUpBucket);
        foreach ($planner->plan($deferred->deferred, $clock->now()) as $followUp) {
            $jobs->dispatchNow($this->continue($followUp->destinations, $clock->now()->getTimestamp()), $this->connection, $this->queue, $followUp->delaySeconds);
        }
    }

    /**
     * The job is not retried (tries = 1). When the package's send log is enabled (mobile-push.log.sinks), every
     * destination of the job is recorded there as OutcomeUnknown: the worker failed while the job was running, so
     * some destinations may have been reached and the others are omitted (best effort). The log records carry only
     * the keyed index and masked form of each destination, never the token. Without sinks, one warning goes to the
     * application logger, without any destination value.
     */
    public function failed(?\Throwable $exception = null): void
    {
        try {
            $sinks = app()->bound(SinkManager::class) ? app(SinkManager::class) : null;
            if ($sinks instanceof SinkManager && $sinks->isEnabled()) {
                $binding = BindingRef::fromArray($this->binding);
                $reason = Reason::of(ReasonCode::Unknown, $exception === null ? 'job_failed' : 'job_failed:'.(new \ReflectionClass($exception))->getShortName(),
                    'The light-profile send job failed while it was running; it is not retried. This destination may or may not have been reached.');
                foreach ($this->items() as $item) {
                    $sinks->recordResult($binding, new SendResult($item->destination, SendStatus::OutcomeUnknown, $reason), [
                        'operation' => $this->validateOnly ? 'validate' : 'send',
                        'traffic_class' => $this->trafficClass,
                        'effect_id' => $this->effectId,
                    ]);
                }
                $sinks->flush();

                return;
            }

            app(LoggerInterface::class)->warning('mobile-push: a light-profile send job failed; it is not retried (best effort: destinations not reached are omitted).', $this->context() + [
                'exception' => $exception === null ? null : $exception::class,
            ]);
        } catch (\Throwable) {
            // reporting must never fail the failure handler
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'mobile-push',
            'provider:'.$this->binding['provider'],
            'connection:'.$this->binding['name'],
            'scope:'.$this->binding['scope'],
            'effect:'.$this->effectId,
        ];
    }

    public function displayName(): string
    {
        return sprintf('mobile-push send %s/%s (%d destinations%s)', $this->binding['provider'], $this->binding['name'],
            count($this->destinations), $this->continuation > 0 ? ', continuation '.$this->continuation : '');
    }

    /** @return \Generator<int, SendItem> */
    private function items(): \Generator
    {
        foreach ($this->destinations as $entry) {
            /** @var array{k: string, v: string, r?: array{0: string, 1: int, 2: string|null, 3: string|null}, b?: array<string, mixed>} $entry */
            yield QueuedDestination::fromArray($entry)->toSendItem();
        }
    }

    /** @param  list<QueuedDestination>  $destinations */
    private function continue(array $destinations, int $now): self
    {
        return new self(
            $this->binding,
            $this->message,
            array_map(static fn (QueuedDestination $d): array => $d->toArray(), $destinations),
            $this->effectId,
            $this->effectKey,
            $this->trafficClass,
            $this->validateOnly,
            $this->expiresAtMs,
            $this->continuation + 1,
            $this->timeout,
            $now,
        );
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'effect_id' => $this->effectId,
            'provider' => $this->binding['provider'],
            'connection' => $this->binding['name'],
            'scope' => $this->binding['scope'],
            'destinations' => count($this->destinations),
            'continuation' => $this->continuation,
        ];
    }
}
