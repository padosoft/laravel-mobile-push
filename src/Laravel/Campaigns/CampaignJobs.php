<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Contracts\Bus\Dispatcher;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Laravel\Queue\QueueSettings;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Queue\SendJobDispatcher;

/**
 * Puts campaign work on the queue: planner slices and the relays of the campaign's effect, on the campaign's queue
 * (default: the bulk queue) and queue connection.
 *
 * @internal
 */
final readonly class CampaignJobs
{
    public function __construct(
        private Dispatcher $bus,
        private SendJobDispatcher $sendJobs,
        private QueueSettings $queue,
    ) {}

    public function plan(Campaign $campaign, int $expect, int $delaySeconds = 0, int $early = 0): void
    {
        $job = new PlanCampaignJob($campaign->scope, $campaign->id, $expect, $early, $this->queue->timeout);
        $job->onConnection($campaign->queueConnection ?? $this->queue->connection);
        $job->onQueue($this->queueOf($campaign));
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        $job->beforeCommit();
        $this->bus->dispatch($job);
    }

    public function relay(Campaign $campaign): void
    {
        if ($campaign->effectId === null) {
            return;
        }
        $this->sendJobs->dispatchNow(
            new RelayDeliveriesJob($campaign->scope, $campaign->effectId, null, $this->queue->timeout),
            $campaign->queueConnection,
            $this->queueOf($campaign),
        );
    }

    /** Lease of one planner run: longer than the job may live (the worker kills it at the timeout). */
    public function leaseSeconds(): int
    {
        return $this->queue->timeout + 30;
    }

    private function queueOf(Campaign $campaign): string
    {
        return $campaign->queue ?? $this->queue->queueFor(TrafficClass::Bulk);
    }
}
