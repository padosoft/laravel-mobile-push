<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Laravel\Devices\DeviceOutcomeApplier;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Sending\Events\AuthTokenRefreshed;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitClosed;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitOpened;
use Padosoft\MobilePush\Laravel\Sending\Events\CredentialFailedOver;
use Padosoft\MobilePush\Laravel\Sending\Events\DestinationInvalidated;
use Padosoft\MobilePush\Laravel\Sending\Events\DestinationQuarantined;
use Padosoft\MobilePush\Laravel\Sending\Events\DestinationView;
use Padosoft\MobilePush\Laravel\Sending\Events\ProviderThrottled;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Laravel\Sending\Events\ResultView;
use Psr\Log\LoggerInterface;

/**
 * The framework side of one send call: registered on the Sender for that call (Sender::withObserver()).
 *
 * For every result, in this order and each step isolated from the others: the device registry outcome
 * (quarantine / invalidate / last sent, only for destinations with a DestinationRef and only when the devices
 * module is enabled), the send log (SinkManager, with the runtime bearers as `secrets`), the Laravel events.
 * Nothing here can change a result or cause a resend: every failure is swallowed, counted and logged by class name
 * only.
 */
final class SendingObserver implements SendObserver
{
    /** @var array<int, array{duration: float, bytes: int|null, push_type: string|null}> per SendItem object id */
    private array $attempts = [];

    private int $failures = 0;

    /**
     * @param  \Closure(): list<string>  $secrets  runtime secrets to redact (bearers handed out so far)
     * @param  string  $perResult  auto | always | never
     */
    public function __construct(
        private readonly BindingRef $binding,
        private readonly TrafficClass $trafficClass,
        private readonly bool $validateOnly,
        private readonly string $perResult,
        private readonly ?Dispatcher $events,
        private readonly TokenIndex $index,
        private readonly ?DeviceOutcomeApplier $devices,
        private readonly ?SinkManager $sinks,
        private readonly \Closure $secrets,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function attemptFinished(AttemptReport $attempt): void
    {
        $id = spl_object_id($attempt->item);
        $previous = $this->attempts[$id] ?? ['duration' => 0.0, 'bytes' => null, 'push_type' => null];
        $headers = $attempt->request['headers'] ?? [];
        $pushType = is_array($headers) && is_string($headers['apns-push-type'] ?? null) ? $headers['apns-push-type'] : null;
        $bytes = $attempt->request['body_bytes'] ?? null;

        $this->attempts[$id] = [
            'duration' => $previous['duration'] + $attempt->durationMs,
            'bytes' => is_int($bytes) ? $bytes : $previous['bytes'],
            'push_type' => $pushType ?? $previous['push_type'],
        ];
    }

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        $this->record($result, $item);
    }

    /** Handle one final result (also used for results the manager produces itself, such as binding mismatches). */
    public function record(SendResult $result, ?SendItem $item = null): void
    {
        $attempt = null;
        if ($item !== null) {
            $id = spl_object_id($item);
            $attempt = $this->attempts[$id] ?? null;
            unset($this->attempts[$id]);
        }

        $registryChange = $this->applyToRegistry($result);
        $this->log($result, $attempt);

        $view = null;
        if ($result->destinationAction !== DestinationAction::None) {
            $view = $this->guard(fn (): DestinationView => DestinationView::of($result->destination, $this->index, $this->binding->scope));
            if ($view instanceof DestinationView) {
                $this->dispatch($result->destinationAction === DestinationAction::Invalidate
                    ? new DestinationInvalidated($this->binding, $view, $result->reason->code, $result->reason->rawReason, $registryChange, $result->invalidatedAtMs)
                    : new DestinationQuarantined($this->binding, $view, $result->reason->code, $result->reason->rawReason, $registryChange, $result->invalidatedAtMs));
            }
        }

        if ($this->wantsPerResultEvent()) {
            $resultView = $this->guard(fn (): ResultView => ResultView::of($result, $this->index, $this->binding->scope));
            if ($resultView instanceof ResultView) {
                $this->dispatch(new PushResultProduced($this->binding, $resultView, $this->trafficClass, $result));
            }
        }
    }

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void
    {
        $this->dispatch(new AuthTokenRefreshed($binding, $credentialGeneration, $credentialIdentity));
    }

    public function guardTransition(GuardTransition $transition): void
    {
        $event = match ($transition->transition) {
            BreakerTransition::Opened, BreakerTransition::Reopened => new CircuitOpened($transition->binding, $transition->guard, $transition->transition, $transition->credentialGeneration),
            BreakerTransition::Closed => new CircuitClosed($transition->binding, $transition->guard, $transition->transition, $transition->credentialGeneration),
            BreakerTransition::FailedOver => new CredentialFailedOver($transition->binding, $transition->guard, $transition->transition, $transition->credentialGeneration),
            BreakerTransition::HalfOpened, BreakerTransition::None => null,
        };
        if ($event !== null) {
            $this->dispatch($event);
        }
    }

    public function providerThrottled(BindingRef $binding, string $accountKey, \DateTimeImmutable $until): void
    {
        $this->dispatch(new ProviderThrottled($binding, $accountKey, $until));
    }

    /** Number of listener / sink / registry failures swallowed so far. */
    public function failures(): int
    {
        return $this->failures;
    }

    private function wantsPerResultEvent(): bool
    {
        return match ($this->perResult) {
            'always' => true,
            'never' => false,
            default => $this->trafficClass === TrafficClass::Transactional,
        };
    }

    private function applyToRegistry(SendResult $result): ?string
    {
        $ref = $result->destination->ref();
        if ($ref === null || $this->devices === null) {
            return null;
        }
        $devices = $this->devices;

        $change = $this->guard(static function () use ($devices, $result, $ref): ?string {
            if ($result->destinationAction !== DestinationAction::None) {
                return $devices->applyResult($result)->change->value;
            }
            if ($result->status === SendStatus::Accepted) {
                $devices->markSent($ref);
            }

            return null;
        });

        return is_string($change) ? $change : null;
    }

    /** @param  array{duration: float, bytes: int|null, push_type: string|null}|null  $attempt */
    private function log(SendResult $result, ?array $attempt): void
    {
        if ($this->sinks === null || ! $this->sinks->isEnabled()) {
            return;
        }
        $sinks = $this->sinks;
        $context = [
            'secrets' => ($this->secrets)(),
            'operation' => $this->validateOnly ? 'validate' : 'send',
            'traffic_class' => $this->trafficClass->value,
        ];
        if ($attempt !== null) {
            $context['duration_ms'] = (int) round($attempt['duration']);
            $context['payload_bytes'] = $attempt['bytes'];
            $context['push_type'] = $attempt['push_type'];
        }
        $binding = $this->binding;
        $this->guard(static function () use ($sinks, $binding, $result, $context): null {
            $sinks->recordResult($binding, $result, $context);

            return null;
        });
    }

    private function dispatch(object $event): void
    {
        if ($this->events === null) {
            return;
        }
        $events = $this->events;
        $this->guard(static function () use ($events, $event): null {
            $events->dispatch($event);

            return null;
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $step
     * @return T|false false when the step threw
     */
    private function guard(\Closure $step): mixed
    {
        try {
            return $step();
        } catch (\Throwable $e) {
            $this->failures++;
            try {
                $this->logger?->warning('mobile-push: a listener, log sink or registry update failed; the send result is unaffected.', [
                    'binding' => $this->binding->name,
                    'exception' => $e::class,
                ]);
            } catch (\Throwable) {
                // a failing logger is ignored as well
            }

            return false;
        }
    }
}
