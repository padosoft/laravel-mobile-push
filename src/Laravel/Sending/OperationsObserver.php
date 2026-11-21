<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Psr\Log\LoggerInterface;

/**
 * The operations side of one send call (registered next to SendingObserver): Prometheus metrics for every result,
 * throttle and breaker transition, and the operational alert of a breaker transition (circuit opened, credential
 * failed over; a closed circuit re-arms the alert).
 *
 * Isolated like every observer: a failing metrics store or alert channel is swallowed and counted, it never changes
 * a result, never causes a resend and never reaches the caller. Only bounded labels are recorded (binding, provider,
 * environment, status, reason code, traffic class): never a destination, user or campaign id.
 */
final class OperationsObserver implements SendObserver
{
    private int $failures = 0;

    public function __construct(
        private readonly BindingRef $binding,
        private readonly TrafficClass $trafficClass,
        private readonly ?MetricsRecorder $metrics,
        private readonly ?OpsAlerter $alerter,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function attemptFinished(AttemptReport $attempt): void {}

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        $this->record($result);
    }

    /** Count one final result (also used for results the manager produces itself, such as binding mismatches). */
    public function record(SendResult $result): void
    {
        $metrics = $this->metrics;
        if ($metrics === null) {
            return;
        }
        $binding = $this->binding;
        $class = $this->trafficClass;
        $this->guard(static fn () => $metrics->record($binding, $result, $class));
    }

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void {}

    public function guardTransition(GuardTransition $transition): void
    {
        $metrics = $this->metrics;
        if ($metrics !== null) {
            $this->guard(static fn () => $metrics->recordGuardTransition($transition));
        }
        $alerter = $this->alerter;
        if ($alerter !== null) {
            $this->guard(static fn () => $alerter->guardTransition($transition));
        }
    }

    public function providerThrottled(BindingRef $binding, string $accountKey, \DateTimeImmutable $until): void
    {
        $metrics = $this->metrics;
        if ($metrics === null) {
            return;
        }
        $class = $this->trafficClass;
        $this->guard(static fn () => $metrics->recordThrottle($binding, $class, $until));
    }

    /** Number of metrics / alert failures swallowed so far. */
    public function failures(): int
    {
        return $this->failures;
    }

    /** @param  \Closure(): mixed  $step */
    private function guard(\Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            $this->failures++;
            try {
                $this->logger?->warning('mobile-push: recording a metric or raising an alert failed; the send result is unaffected.', [
                    'binding' => $this->binding->name,
                    'exception' => $e::class,
                ]);
            } catch (\Throwable) {
                // a failing logger is ignored as well
            }
        }
    }
}
