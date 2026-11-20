<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

/**
 * A destination for send log records. Records are already redacted. A sink may throw: the SinkManager isolates it,
 * counts the failure and never retries.
 */
interface LogSink
{
    public function write(LogRecord ...$records): void;

    public function flush(): void;
}
