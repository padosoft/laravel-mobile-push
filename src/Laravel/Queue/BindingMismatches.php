<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Sending\Events\BindingMismatchDetected;

/**
 * The queued side of the binding-mismatch rule (docs/contracts/identity.md): a job prepared for a connection that no
 * longer resolves, or now points to another app, provider or environment, sends nothing; every destination comes back
 * Skipped(BindingMismatch) without an attempt; BindingMismatchDetected is dispatched once per job run; the results go
 * to the log sinks when they are enabled. Exactly what PushDispatcher::send() does for a direct send, for the two
 * paths that learn about the mismatch before reaching send() (defaultOptions() throws): the light SendPushJob and the
 * durable relay.
 */
final readonly class BindingMismatches
{
    public function __construct(
        private ?Dispatcher $events = null,
        /** @var (\Closure(): ?SinkManager)|null */
        private ?\Closure $sinks = null,
    ) {}

    /** Dispatch the event (never throws) and return the reason every destination is skipped with. */
    public function detected(PushDispatcher $dispatcher, BindingRef $expected, UnknownConnectionException $e): Reason
    {
        try {
            $current = $dispatcher->binding($expected->name, $expected->scope);
        } catch (\Throwable) {
            $current = null;
        }
        $hint = $e->getMessage() !== '' ? $e->getMessage() : sprintf(
            'The connection "%s" in scope "%s" no longer resolves to the binding this push was prepared for; nothing was sent and no other connection was used.',
            $expected->name, $expected->scope,
        );

        try {
            $this->events?->dispatch(new BindingMismatchDetected($expected->scope, $expected->name, $expected, $current, $hint));
        } catch (\Throwable) {
            // a listener never turns a skipped send into a failed job
        }

        return Reason::of(ReasonCode::BindingMismatch, null, $hint);
    }

    /**
     * Hand the skipped results to the log sinks (when enabled), then flush them.
     *
     * @param  iterable<SendResult>  $results
     * @param  array<string, string|null>  $context
     */
    public function log(BindingRef $binding, iterable $results, array $context = []): void
    {
        try {
            $sinks = $this->sinks === null ? null : ($this->sinks)();
            if ($sinks === null || ! $sinks->isEnabled()) {
                return;
            }
            foreach ($results as $result) {
                $sinks->recordResult($binding, $result, $context);
            }
            $sinks->flush();
        } catch (\Throwable) {
            // logging never fails the job
        }
    }
}
