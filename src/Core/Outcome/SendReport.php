<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

use Padosoft\MobilePush\Core\Exceptions\SendFailedException;

/**
 * Streaming report: bounded counters plus a cursor over the results.
 *
 * Results are pulled lazily from the source and tallied as they pass. Only the first `$retain`
 * results are kept in memory, so a report over millions of destinations stays flat.
 *
 * @implements \IteratorAggregate<int, SendResult>
 */
final class SendReport implements \Countable, \IteratorAggregate
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<SendResult> */
    private array $retained = [];

    private int $total = 0;

    /** @var \Iterator<mixed, SendResult>|null */
    private ?\Iterator $source;

    /**
     * @param  iterable<SendResult>  $results
     */
    public function __construct(iterable $results = [], private readonly int $retain = 1000)
    {
        $this->source = $results instanceof \Iterator ? $results : (static fn () => yield from $results)();
        foreach (SendStatus::cases() as $status) {
            $this->counts[$status->value] = 0;
        }
    }

    /**
     * Cursor over the results: live results while the source lasts; afterwards the retained ones.
     *
     * @return \Generator<int, SendResult>
     */
    public function getIterator(): \Generator
    {
        if ($this->source === null) {
            yield from $this->retained;

            return;
        }

        while (($result = $this->pull()) !== null) {
            yield $result;
        }
    }

    /** @return \Generator<int, SendResult> */
    public function results(): \Generator
    {
        return $this->getIterator();
    }

    /**
     * @param  callable(SendResult): void  $consumer
     */
    public function each(callable $consumer): static
    {
        foreach ($this->getIterator() as $result) {
            $consumer($result);
        }

        return $this;
    }

    /** @return array<string, int> status value => count (drains the source) */
    public function counts(): array
    {
        $this->drain();

        return $this->counts;
    }

    public function countOf(SendStatus $status): int
    {
        return $this->counts()[$status->value];
    }

    public function count(): int
    {
        $this->drain();

        return $this->total;
    }

    public function accepted(): int
    {
        return $this->countOf(SendStatus::Accepted);
    }

    public function hasUnknown(): bool
    {
        return $this->countOf(SendStatus::OutcomeUnknown) > 0;
    }

    public function allAccepted(): bool
    {
        return $this->count() > 0 && $this->accepted() === $this->total;
    }

    /**
     * The first results kept in memory (at most `$retain`).
     *
     * @return list<SendResult>
     */
    public function retained(): array
    {
        $this->drain();

        return $this->retained;
    }

    public function isTruncated(): bool
    {
        return $this->count() > count($this->retained);
    }

    /**
     * @throws SendFailedException when at least one destination was rejected, failed, expired or has an unknown outcome
     */
    public function throw(): static
    {
        $counts = $this->counts();
        $bad = $counts[SendStatus::Rejected->value] + $counts[SendStatus::Failed->value]
            + $counts[SendStatus::OutcomeUnknown->value] + $counts[SendStatus::Expired->value];

        if ($bad > 0) {
            throw new SendFailedException(sprintf(
                '%d of %d push destinations were not accepted (rejected: %d, failed: %d, unknown outcome: %d, expired: %d).',
                $bad, $this->total, $counts[SendStatus::Rejected->value], $counts[SendStatus::Failed->value],
                $counts[SendStatus::OutcomeUnknown->value], $counts[SendStatus::Expired->value],
            ));
        }

        return $this;
    }

    private function pull(): ?SendResult
    {
        if ($this->source === null) {
            return null;
        }

        if (! $this->source->valid()) {
            $this->source = null;

            return null;
        }

        $result = $this->source->current();
        $this->source->next();

        $this->counts[$result->status->value]++;
        $this->total++;
        if (count($this->retained) < $this->retain) {
            $this->retained[] = $result;
        }

        return $result;
    }

    private function drain(): void
    {
        while ($this->pull() !== null) {
            // tally only
        }
    }
}
