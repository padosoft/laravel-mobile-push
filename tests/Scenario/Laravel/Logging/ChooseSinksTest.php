<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Logging\LogRecord;
use Padosoft\MobilePush\Laravel\Logging\LogSink;
use Padosoft\MobilePush\Laravel\Logging\PushLog;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\RecordingTelemetry;
use Psr\Log\LogLevel;

afterEach(fn () => SinkManager::forgetExtensions());

/** Choose your sinks from the environment. */
it('enables exactly the sinks named in MOBILE_PUSH_LOG_SINKS and each one receives the record', function (string|array $value, array $expected) {
    F::migrate();
    $telemetry = new RecordingTelemetry;
    app()->instance(OpenTelemetry\API\Trace\TracerProviderInterface::class, $telemetry);
    app()->instance(OpenTelemetry\API\Metrics\MeterProviderInterface::class, $telemetry);
    $handler = F::configure($value);

    $manager = F::manager();
    $manager->recordResult(F::binding(), F::result(F::token(1)), ['push_type' => 'alert']);
    $manager->flush();

    expect($manager->enabled())->toBe($expected)
        ->and(count($handler->getRecords()))->toBe(in_array('log', $expected, true) ? 1 : 0)
        ->and(PushLog::query()->count())->toBe(in_array('database', $expected, true) ? 1 : 0)
        ->and(count($telemetry->spans))->toBe(in_array('otel', $expected, true) ? 1 : 0);

    foreach ($expected as $name) {
        expect($manager->stats()['sinks'][$name]['written'])->toBe(1);
    }
})->with([
    'nothing' => ['', []],
    'log' => ['log', ['log']],
    'database' => ['database', ['database']],
    'otel' => ['otel', ['otel']],
    'log,database' => ['log,database', ['log', 'database']],
    'log,otel' => ['log,otel', ['log', 'otel']],
    'database,otel' => [' database , otel ', ['database', 'otel']],
    'all three' => ['log,database,otel', ['log', 'database', 'otel']],
    'array form' => [['log', 'otel'], ['log', 'otel']],
]);

it('writes a stable message with the status mapped to a configurable level', function () {
    $handler = F::configure('log', ['levels' => ['rejected' => 'error']]);

    $manager = F::manager();
    $manager->recordResult(F::binding(), F::result(F::token(1)));
    $manager->recordResult(F::binding(), F::result(F::token(2), SendStatus::Rejected, 'bad', Padosoft\MobilePush\Core\Outcome\ReasonCode::DestinationRejected));
    $manager->recordResult(F::binding(), F::result(F::token(3), SendStatus::OutcomeUnknown));
    $manager->flush();

    $records = $handler->getRecords();
    expect(array_map(fn ($r) => [$r->message, strtolower($r->level->getName())], $records))->toBe([
        ['mobile-push.send', LogLevel::DEBUG],
        ['mobile-push.send', LogLevel::ERROR],
        ['mobile-push.send', LogLevel::WARNING],
    ])->and($records[1]->context['status'])->toBe('rejected')
        ->and($records[1]->context['reason_code'])->toBe('destination_rejected');
});

it('lets the host app add its own sink and rejects unknown names with a remedy', function () {
    $received = [];
    SinkManager::extend('custom', function () use (&$received): LogSink {
        return new class($received) implements LogSink
        {
            public function __construct(private array &$received) {}

            public function write(LogRecord ...$records): void
            {
                foreach ($records as $r) {
                    $this->received[] = $r->status->value;
                }
            }

            public function flush(): void {}
        };
    });
    F::configure('custom');
    F::manager()->recordResult(F::binding(), F::result(F::token(1)));
    F::manager()->flush();
    expect($received)->toBe(['accepted']);

    F::configure('nonsense');
    expect(fn () => F::manager())->toThrow(ConfigurationException::class, 'Unknown log sink');
});

it('refuses an invalid payload level or database mode at boot', function () {
    config(['mobile-push.log.payload' => 'everything']);
    expect(fn () => Padosoft\MobilePush\Laravel\Logging\LoggingModule::boot($this->app))->toThrow(ConfigurationException::class);

    config(['mobile-push.log.payload' => 'none', 'mobile-push.log.sinks' => 'database', 'mobile-push.log.database.mode' => 'some']);
    expect(fn () => Padosoft\MobilePush\Laravel\Logging\LoggingModule::boot($this->app))->toThrow(ConfigurationException::class);
});
