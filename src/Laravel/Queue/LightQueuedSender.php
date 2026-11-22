<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Core\Ledger\Ulid;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Psr\Clock\ClockInterface;

/**
 * Light profile: no tables. The destinations are cut into small encrypted SendPushJobs (by count and by estimated
 * bytes) and dispatched on the queue named by QueueOptions. Best effort, see SendPushJob.
 *
 * The returned id is a fresh ULID naming this send in logs and Horizon tags. When the caller gave no effect key,
 * that id is used as the effect key, so the provider message ids stay the same across this send's continuations.
 */
final readonly class LightQueuedSender implements QueuedSender
{
    public function __construct(
        private SendJobDispatcher $jobs,
        private QueueSettings $settings,
        private ClockInterface $clock,
    ) {}

    public function queue(BindingRef $binding, Message $message, iterable $destinations, SendOptions $options, QueueOptions $queue): string
    {
        $now = $this->clock->now();
        $effectId = Ulid::generate($now);
        $serialized = serialize($message);
        // The expiry is pinned NOW: a job that waits in the queue never extends the message's life.
        $expiresAt = $options->expiresAt ?? ($message instanceof PushMessage ? $message->resolveExpiry($now) : null);

        $chunker = new DestinationChunker($this->settings->chunk, $this->settings->chunkBytes);
        foreach ($chunker->chunks(self::queued($destinations), strlen($serialized)) as $chunk) {
            $job = new SendPushJob(
                $binding->toArray(),
                $serialized,
                array_map(static fn (QueuedDestination $d): array => $d->toArray(), $chunk),
                $effectId,
                $options->effectKey ?? $effectId,
                $options->trafficClass->value,
                $options->validateOnly,
                LedgerTime::msOrNull($expiresAt),
                0,
                $this->settings->timeout,
                $now->getTimestamp(),
            );
            $this->jobs->dispatch($job, $queue, $options->trafficClass);
        }

        return $effectId;
    }

    /**
     * @param  iterable<Destination>  $destinations
     * @return \Generator<int, QueuedDestination>
     */
    private static function queued(iterable $destinations): \Generator
    {
        foreach ($destinations as $destination) {
            yield QueuedDestination::of($destination);
        }
    }
}
