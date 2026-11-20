<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;

/**
 * The single place that puts mobile-push send work on a queue (light jobs, relay jobs, continuations, follow-ups,
 * the sweeper's relays). Applies the queue connection, queue name and delay, and the after-commit rule.
 */
final readonly class SendJobDispatcher
{
    public function __construct(
        private Dispatcher $bus,
        private QueueSettings $settings,
        private ?ConnectionResolverInterface $db = null,
    ) {}

    /**
     * Light profile: the job itself is marked after-commit, so Laravel holds it until the open transaction (on any
     * connection) commits, and drops it on rollback.
     */
    public function dispatch(SendPushJob|RelayDeliveriesJob|SweepLedgerJob $job, QueueOptions $queue, TrafficClass $trafficClass = TrafficClass::Transactional): void
    {
        $this->route($job, $queue, $trafficClass);
        $queue->afterCommit && $this->settings->afterCommit ? $job->afterCommit() : $job->beforeCommit();
        $this->bus->dispatch($job);
    }

    /**
     * Durable profile: dispatch once the transaction that holds the ledger intent commits (immediately when none is
     * open); nothing is dispatched on rollback. The gap between commit and dispatch (a crash right after commit) is
     * closed by the sweeper, not here.
     */
    public function dispatchAfterLedgerCommit(RelayDeliveriesJob $job, QueueOptions $queue, ?string $ledgerConnection, TrafficClass $trafficClass = TrafficClass::Transactional): void
    {
        $this->route($job, $queue, $trafficClass);
        $job->beforeCommit();

        $send = fn () => $this->bus->dispatch($job);
        if (! $queue->afterCommit || ! $this->settings->afterCommit || $this->db === null) {
            $send();

            return;
        }

        try {
            $connection = $this->db->connection($ledgerConnection);
            if ($connection instanceof Connection) {
                $connection->afterCommit($send);

                return;
            }
        } catch (\RuntimeException) {
            // no transactions manager: nothing can be pending, dispatch now
        }
        $send();
    }

    /** A job dispatched from inside a worker (continuation, follow-up, sweep): never waits for a commit. */
    public function dispatchNow(SendPushJob|RelayDeliveriesJob|SweepLedgerJob $job, ?string $connection, ?string $queue, int $delaySeconds = 0): void
    {
        $job->onConnection($connection ?? $this->settings->connection);
        $job->onQueue($queue ?? $this->settings->queue);
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        $job->beforeCommit();
        $this->bus->dispatch($job);
    }

    private function route(SendPushJob|RelayDeliveriesJob|SweepLedgerJob $job, QueueOptions $queue, TrafficClass $trafficClass): void
    {
        $job->onConnection($queue->queueConnection ?? $this->settings->connection);
        $job->onQueue($queue->queue ?? $this->settings->queueFor($trafficClass));
        if ($queue->delay !== null) {
            $job->delay($queue->delay);
        }
    }
}
