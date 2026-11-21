<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;

/**
 * Single entry point of send logging: record(LogRecord) or recordResult(BindingRef, SendResult, context).
 *
 * Records go into a bounded in-memory buffer, flushed on size, on flush(), on application terminate and after each
 * queue job. Every sink runs in its own try/catch: a failing sink never throws into the caller, never causes a retry
 * (its batch is counted as failed and forgotten) and never starves the other sinks. When the buffer reaches its hard
 * cap the oldest record is dropped and counted.
 */
final class SinkManager
{
    /** @var array<string, \Closure(Application): LogSink> */
    private static array $extensions = [];

    /** @var array<int, LogRecord> */
    private array $buffer = [];

    private int $head = 0;

    private int $tail = 0;

    private int $peak = 0;

    private int $recordErrors = 0;

    /** @var array<string, array{written: int, failed: int, dropped: int, filtered: int, last_error: string|null}> */
    private array $stats = [];

    /**
     * @param  array<string, LogSink>  $sinks
     * @param  int  $flushSize  records that trigger an automatic flush; 0 = flush only on flush()/terminate/queue hooks
     * @param  int  $maxBuffer  hard cap; beyond it the oldest record is dropped
     */
    public function __construct(
        private array $sinks = [],
        private readonly int $flushSize = 100,
        private readonly int $maxBuffer = 10000,
        private readonly ?LogRecordFactory $factory = null,
    ) {
        foreach (array_keys($this->sinks) as $name) {
            $this->stats[$name] = self::emptyStats();
        }
    }

    /**
     * Register a custom sink for `mobile-push.log.sinks`. The closure receives the application.
     *
     * @param  \Closure(Application): LogSink  $factory
     */
    public static function extend(string $name, \Closure $factory): void
    {
        self::$extensions[strtolower($name)] = $factory;
    }

    /** @return array<string, \Closure(Application): LogSink> */
    public static function extensions(): array
    {
        return self::$extensions;
    }

    public static function forgetExtensions(): void
    {
        self::$extensions = [];
    }

    public function add(string $name, LogSink $sink): void
    {
        $this->sinks[$name] = $sink;
        $this->stats[$name] ??= self::emptyStats();
    }

    /** @return list<string> */
    public function enabled(): array
    {
        return array_keys($this->sinks);
    }

    public function isEnabled(): bool
    {
        return $this->sinks !== [];
    }

    /**
     * The payload level the records are built with. None when no sink is enabled: a caller asks for the payload
     * (and builds a copy of it) only when this is not None.
     */
    public function payloadLevel(): PayloadLevel
    {
        return $this->sinks === [] || $this->factory === null ? PayloadLevel::None : $this->factory->payloadLevel();
    }

    /**
     * Build a record from a send outcome and record it. Never throws: a failure to build is counted in
     * stats()['record_errors'].
     *
     * @param  array<string, mixed>  $context  see LogRecordFactory
     */
    public function recordResult(BindingRef $binding, SendResult $result, array $context = []): void
    {
        if ($this->sinks === []) {
            return;
        }

        try {
            if ($this->factory === null) {
                throw new \LogicException('No record factory.');
            }
            $record = $this->factory->make($binding, $result, $context);
        } catch (\Throwable) {
            $this->recordErrors++;

            return;
        }

        $this->record($record);
    }

    public function record(LogRecord $record): void
    {
        if ($this->sinks === []) {
            return;
        }

        try {
            if ($this->buffered() >= max(1, $this->maxBuffer)) {
                unset($this->buffer[$this->head]);
                $this->head++;
                foreach (array_keys($this->sinks) as $name) {
                    $this->stats[$name]['dropped']++;
                }
            }

            $this->buffer[$this->tail++] = $record;
            $this->peak = max($this->peak, $this->buffered());

            if ($this->flushSize > 0 && $this->buffered() >= $this->flushSize) {
                $this->flush();
            }
        } catch (\Throwable) {
            $this->recordErrors++;
        }
    }

    /** Deliver the buffered records to every sink. Never throws. */
    public function flush(): void
    {
        $records = array_values($this->buffer);
        $this->buffer = [];
        $this->head = 0;
        $this->tail = 0;

        if ($records === []) {
            return;
        }

        foreach ($this->sinks as $name => $sink) {
            $this->deliver($name, $sink, $records);
        }
    }

    public function buffered(): int
    {
        return $this->tail - $this->head;
    }

    /**
     * Counters for the health module. "filtered" are records a sink chose not to persist (database mode).
     *
     * @return array{sinks: array<string, array{written: int, failed: int, dropped: int, filtered: int, last_error: string|null}>, buffered: int, peak_buffered: int, record_errors: int}
     */
    public function stats(): array
    {
        return [
            'sinks' => $this->stats,
            'buffered' => $this->buffered(),
            'peak_buffered' => $this->peak,
            'record_errors' => $this->recordErrors,
        ];
    }

    /** @param  list<LogRecord>  $records */
    private function deliver(string $name, LogSink $sink, array $records): void
    {
        $filteredBefore = $sink instanceof ReportsFiltered ? $sink->filtered() : 0;

        try {
            foreach (array_chunk($records, 500) as $batch) {
                $sink->write(...$batch);
            }
            $sink->flush();

            $filtered = ($sink instanceof ReportsFiltered ? $sink->filtered() : 0) - $filteredBefore;
            $stats = $this->stats[$name];
            $stats['filtered'] += $filtered;
            $stats['written'] += max(0, count($records) - $filtered);
            $this->stats[$name] = $stats;
        } catch (\Throwable $e) {
            // Counted, never retried, never rethrown. Only the class name is kept: a message may echo secrets.
            $stats = $this->stats[$name];
            $stats['failed'] += count($records);
            $stats['last_error'] = $e::class;
            $this->stats[$name] = $stats;
        }
    }

    /** @return array{written: int, failed: int, dropped: int, filtered: int, last_error: string|null} */
    private static function emptyStats(): array
    {
        return ['written' => 0, 'failed' => 0, 'dropped' => 0, 'filtered' => 0, 'last_error' => null];
    }
}
