<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/**
 * Every result of one send call on one connection has been read from the report. Not dispatched for a report that
 * is never consumed to the end.
 */
final readonly class PushBatchCompleted
{
    /**
     * @param  array<string, int>  $counts  SendStatus value => number of destinations
     */
    public function __construct(
        public BindingRef $binding,
        public array $counts,
        public int $total,
        public TrafficClass $trafficClass,
        public float $durationMs,
    ) {}
}
