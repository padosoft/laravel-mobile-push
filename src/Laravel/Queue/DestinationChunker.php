<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/**
 * Splits destinations into job-sized chunks: at most `$maxCount` destinations and, estimated, at most `$maxBytes`
 * bytes per job (the job's fixed part, mostly the serialized message, included). A chunk always holds at least one
 * destination, so an oversized message still makes progress one destination per job.
 *
 * Duplicates (same kind and value, same registry generation) are dropped within one call: this is the light
 * profile's in-process deduplication, nothing more (its memory grows with the number of distinct destinations).
 */
final readonly class DestinationChunker
{
    /** Bytes of the job envelope besides the message and the destinations (binding, options, class names). */
    public const ENVELOPE_BYTES = 1024;

    public function __construct(
        private int $maxCount,
        private int $maxBytes,
    ) {
        if ($maxCount < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('Chunk limits must be positive.');
        }
    }

    /**
     * @param  iterable<QueuedDestination>  $destinations
     * @param  int  $fixedBytes  bytes every job carries regardless of its destinations (serialized message)
     * @return \Generator<int, list<QueuedDestination>>
     */
    public function chunks(iterable $destinations, int $fixedBytes = 0): \Generator
    {
        $base = self::ENVELOPE_BYTES + max(0, $fixedBytes);
        $seen = [];
        $chunk = [];
        $bytes = $base;

        foreach ($destinations as $destination) {
            $key = $destination->dedupeKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $size = $destination->estimatedBytes();
            if ($chunk !== [] && (count($chunk) >= $this->maxCount || $bytes + $size > $this->maxBytes)) {
                yield $chunk;
                $chunk = [];
                $bytes = $base;
            }
            $chunk[] = $destination;
            $bytes += $size;
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }
}
