<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\Transport;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The single send pipeline. For every destination, before any external effect and in this order:
 * expiry (RetryPlanner::beforeAttempt), execution mode (SafeModeGuard), destination gates, guards (breakers,
 * throttle, rate limit), compilation, bearer; then one multiplexed Transport::send() per window and pool,
 * classification, guard feedback, token refresh / failover, retry planning, and exactly one SendResult.
 *
 * Streaming: the destinations are consumed lazily in windows and the report is a cursor, so memory stays flat for
 * millions of destinations. send() itself processes the FIRST window before it returns, so a globally invalid
 * message (or configuration) throws from send(); the rest is sent as the report is consumed. A report that is
 * never consumed sends only the first window: always read it (counts(), each(), results()).
 *
 * Guarantees: no destination disappears from the report; a failure of one destination never throws and never
 * erases the others; an observer cannot change a result or cause a resend; nothing sleeps longer than the retry
 * policy's inline threshold; time comes only from the injected clock.
 */
final class Sender
{
    /** @var list<SendObserver> */
    private array $observers;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    private readonly Redactor $redactor;

    private int $observerFailures = 0;

    /**
     * @param  list<SendObserver>  $observers
     * @param  (callable(int): void)|null  $sleeper  waits the given milliseconds for an inline retry (default usleep);
     *                                               never asked for more than RetryPolicy::$inlineThresholdMs
     * @param  int  $reportRetain  results kept in memory by each SendReport
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Guards $guards,
        private readonly SafeModeGuard $safeMode,
        private readonly ClockInterface $clock,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy,
        array $observers = [],
        ?callable $sleeper = null,
        ?Redactor $redactor = null,
        private readonly int $reportRetain = 1000,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->observers = $observers;
        $this->sleeper = $sleeper === null
            ? static function (int $ms): void {
                if ($ms > 0) {
                    usleep($ms * 1000);
                }
            }
        : \Closure::fromCallable($sleeper);
        $this->redactor = $redactor ?? new Redactor;
    }

    public function withObserver(SendObserver $observer): self
    {
        $clone = clone $this;
        $clone->observers[] = $observer;
        $clone->observerFailures = 0;

        return $clone;
    }

    /**
     * @param  iterable<Destination|SendItem>  $destinations  consumed lazily, once
     *
     * @throws ValidationException when the message is invalid for the first destination before anything was sent
     */
    public function send(Driver $driver, Message $message, iterable $destinations, SendOptions $options = new SendOptions): SendReport
    {
        if (! $driver->compiler()->supports($message)) {
            throw new ValidationException(sprintf('The %s driver cannot send a message of type %s.', $driver->binding()->provider->value, $message::class));
        }

        if ($options->validateOnly && ! $driver->supportsValidateOnly()) {
            throw new UnsupportedCapabilityException(sprintf('The %s provider has no validate-only (dry run) mode.', $driver->binding()->provider->value));
        }

        $run = new SendRun(
            $driver,
            $message,
            $options,
            $this->transport,
            $this->guards,
            $this->safeMode,
            $this->clock,
            $options->retryPolicy ?? $this->retryPolicy,
            $this->redactor,
            $this->sleeper,
            fn (\Closure $call) => $this->notify($call, $options->observers),
            $this->observers !== [] || $options->observers !== [],
        );

        $results = $run->results($destinations);
        $results->current();   // run the first window now: global errors surface from send(), not from the report

        return new SendReport($results, $this->reportRetain);
    }

    /** Number of observer calls that threw (they never affect a result). */
    public function observerFailures(): int
    {
        return $this->observerFailures;
    }

    /**
     * Calls every observer, isolating failures.
     *
     * @param  \Closure(SendObserver): void  $call
     * @param  list<SendObserver>  $perCall  observers of this send only, called after the sender's own
     */
    private function notify(\Closure $call, array $perCall = []): void
    {
        foreach ([...$this->observers, ...$perCall] as $observer) {
            try {
                $call($observer);
            } catch (\Throwable $e) {
                $this->observerFailures++;
                try {
                    $this->logger?->warning('mobile-push: a send observer failed; results are unaffected.', [
                        'observer' => $observer::class,
                        'exception' => $e::class,
                    ]);
                } catch (\Throwable) {
                    // a failing logger is ignored as well
                }
            }
        }
    }
}
