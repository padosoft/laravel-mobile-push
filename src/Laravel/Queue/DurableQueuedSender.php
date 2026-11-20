<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;

/**
 * Durable profile (outbox): the intent (effect + one delivery per unique destination) is written to the ledger,
 * joining the caller's open transaction on the ledger connection so it commits or rolls back with the business
 * data; one RelayDeliveriesJob carrying only (scope, effect id) is dispatched once that transaction commits.
 *
 * Same effect key and same message: idempotent, the same effect id comes back and no second relay is dispatched
 * unless new destinations were added. Same key with another message: IdempotencyConflictException, nothing written,
 * nothing queued.
 *
 * Guarantees: once queue() returned inside a committed transaction, every destination is sent at most once per
 * attempt plan and its outcome is recorded before the relay job is acknowledged. A crash between the commit and the
 * dispatch loses only the job, never the intent: the sweeper dispatches the relay.
 */
final readonly class DurableQueuedSender implements QueuedSender
{
    public function __construct(
        private Ledger $ledger,
        private SendJobDispatcher $jobs,
        private QueueSettings $settings,
        private ?string $ledgerConnection = null,
    ) {}

    /** @throws IdempotencyConflictException */
    public function queue(BindingRef $binding, Message $message, iterable $destinations, SendOptions $options, QueueOptions $queue): string
    {
        $intent = $this->ledger->recordIntent(new EffectIntent(
            $binding,
            $message,
            $options->effectKey,
            $options->validateOnly ? OperationKind::Validate : OperationKind::Send,
            expiresAt: $options->expiresAt,
            trafficClass: $options->trafficClass,
        ), $destinations);

        if ($intent->created || $intent->added > 0) {
            $this->jobs->dispatchAfterLedgerCommit(
                new RelayDeliveriesJob($intent->effect->scope, $intent->effect->id, null, $this->settings->timeout),
                $queue,
                $this->ledgerConnection,
                $options->trafficClass,
            );
        }

        return $intent->effect->id;
    }
}
