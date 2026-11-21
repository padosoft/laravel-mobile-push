<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Psr\Clock\ClockInterface;

/**
 * @internal the wall-clock deadline of one light-profile job (mobile-push.queue.chunk_deadline, the same setting the
 * durable relay enforces, measured the same way: from the injected clock, deadline = start + chunk_deadline).
 *
 * The Sender pulls destinations lazily and only between windows: it fills a window, sends it, hands its results to
 * the reader, and only then asks for the next destination. items() decides at each pull whether to hand over one
 * more destination. It stops when the deadline has passed, or when the time left is shorter than the slowest gap seen
 * between two pulls in this job (that gap is one whole window: compile, transport, in-process retries), exactly as the
 * relay stops claiming when the time left is shorter than its slowest chunk. The first destination is always handed
 * over, so every job makes progress and a chain of handovers is at most as long as the chunk.
 *
 * Every destination it yields is in the Sender's hands and gets exactly one result from it (sent, deferred, unknown,
 * ...); remaining() is exactly the destinations it never yielded, i.e. never compiled and never written. The two
 * sets are disjoint and together are the whole chunk: nothing is sent twice, nothing is dropped.
 */
final class ChunkDeadline
{
    private readonly int $deadlineMs;

    private int $slowestMs = 0;

    private int $yielded = 0;

    private bool $reached = false;

    public function __construct(private readonly ClockInterface $clock, int $deadlineSeconds)
    {
        $this->deadlineMs = LedgerTime::ms($clock->now()) + $deadlineSeconds * 1000;
    }

    /**
     * @param  list<array<string, mixed>>  $entries  QueuedDestination::toArray() entries, in order
     * @return \Generator<int, SendItem>
     */
    public function items(array $entries): \Generator
    {
        $last = null;
        foreach ($entries as $entry) {
            $now = LedgerTime::ms($this->clock->now());
            if ($last !== null) {
                $this->slowestMs = max($this->slowestMs, $now - $last);
                $left = $this->deadlineMs - $now;
                if ($left <= 0 || ($this->slowestMs > 0 && $left < $this->slowestMs)) {
                    $this->reached = true;

                    return;
                }
            }
            $this->yielded++;
            $last = $now;

            /** @var array{k: string, v: string, r?: array{0: string, 1: int, 2: string|null, 3: string|null}, b?: array<string, mixed>} $entry */
            yield QueuedDestination::fromArray($entry)->toSendItem();
        }
    }

    /** True when items() stopped before the end of the chunk. */
    public function reached(): bool
    {
        return $this->reached;
    }

    /**
     * The entries items() never yielded (empty unless the deadline was reached), with their budgets unchanged.
     *
     * @param  list<array<string, mixed>>  $entries  the same list given to items()
     * @return list<QueuedDestination>
     */
    public function remaining(array $entries): array
    {
        if (! $this->reached) {
            return [];
        }

        /** @var list<array{k: string, v: string, r?: array{0: string, 1: int, 2: string|null, 3: string|null}, b?: array<string, mixed>}> $rest */
        $rest = array_slice($entries, $this->yielded);

        return array_map(QueuedDestination::fromArray(...), $rest);
    }
}
