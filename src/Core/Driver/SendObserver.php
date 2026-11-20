<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * Side channel of the Sender for the framework layer (events, log sinks, the durable ledger).
 *
 * Contract: an observer can never change a result or cause a resend. Every call is wrapped; an exception thrown
 * here is swallowed and counted (Sender::observerFailures()). Everything handed over is safe to log: requests and
 * responses arrive already redacted, bearers and receipt markers never appear. Destinations are typed objects
 * that redact themselves in dumps; never log Destination::value().
 */
interface SendObserver
{
    /** One request was answered (or failed at transport level) and classified. */
    public function attemptFinished(AttemptReport $attempt): void;

    /**
     * The final result of one destination for this send call, with the budget to persist (durable profile) and the
     * input item it belongs to (delivery id, resumed provider message id).
     */
    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void;

    /** The bearer of a credential generation was refreshed after the provider rejected it as expired. */
    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void;

    /** A breaker changed state (opened, half-opened, reopened, closed, failed over to the secondary credential). */
    public function guardTransition(GuardTransition $transition): void;

    /** The provider account was paused (quota, provider unavailable) until the given instant. */
    public function providerThrottled(BindingRef $binding, string $accountKey, \DateTimeImmutable $until): void;
}
