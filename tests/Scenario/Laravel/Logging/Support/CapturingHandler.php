<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Monolog handler that keeps everything it is given in a static list (Laravel builds handlers itself). */
final class CapturingHandler extends AbstractProcessingHandler
{
    /** @var list<LogRecord> */
    public static array $records = [];

    protected function write(LogRecord $record): void
    {
        self::$records[] = $record;
    }

    /** The lines as an exporter would see them. */
    public static function dump(): string
    {
        return json_encode(array_map(static fn (LogRecord $r): array => [$r->message, $r->context, $r->formatted], self::$records), JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
