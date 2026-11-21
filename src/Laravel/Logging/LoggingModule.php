<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Integration\ModuleMigrations;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;

/**
 * Optional send logging in any combination of sinks: `log` (a Laravel log channel), `database`, `otel`.
 *
 * Config (all read with defaults), under mobile-push.log:
 *  - sinks: array or comma-separated string (env MOBILE_PUSH_LOG_SINKS=log,database,otel); default none.
 *    Names registered with SinkManager::extend() are accepted too.
 *  - payload: none | keys | full (default none)
 *  - buffer.size (default 100, 0 = flush only on terminate/queue hooks), buffer.max (default 10000)
 *  - levels: map SendStatus value => PSR-3 level
 *  - channel: log channel name (null = default channel)
 *  - database.{table='mobile_push_logs', connection=null, mode='failures', sample_rate=0.01, retention_days=30,
 *    insert_chunk=100}
 *
 * Hand a result to the module with app(SinkManager::class)->recordResult($binding, $result, $context).
 */
final class LoggingModule implements Module
{
    public const PUBLISH_TAG = 'mobile-push-migrations-logs';

    public const BUILT_IN = ['log', 'database', 'otel'];

    public static function register(Application $app): void
    {
        $app->singleton(LogRecordFactory::class, static fn (Application $app): LogRecordFactory => new LogRecordFactory(
            $app->make(Redactor::class),
            $app->make(TokenIndex::class),
            $app->make(ClockInterface::class),
            self::payloadLevel($app),
        ));

        $app->singleton(SinkManager::class, static function (Application $app): SinkManager {
            $sinks = [];
            foreach (self::sinkNames($app) as $name) {
                $sinks[$name] = self::makeSink($app, $name);
            }
            $size = max(0, self::int($app, 'buffer.size', 100));
            $max = max(1, self::int($app, 'buffer.max', 10000));

            return new SinkManager($sinks, min($size, $max), $max, $app->make(LogRecordFactory::class));
        });

        $app->singleton(LogPruner::class, static fn (Application $app): LogPruner => new LogPruner($app->make(ClockInterface::class)));
    }

    public static function boot(Application $app): void
    {
        $names = self::sinkNames($app);
        self::payloadLevel($app);

        if (in_array('otel', $names, true) && ! OpenTelemetrySink::isInstalled()) {
            throw new ConfigurationException("The 'otel' log sink is enabled but the OpenTelemetry API is not installed (an absent exporter is not telemetry delivered). Run `composer require open-telemetry/api` and configure the SDK/exporter in the application, or remove 'otel' from mobile-push.log.sinks (MOBILE_PUSH_LOG_SINKS).");
        }
        if (in_array('database', $names, true)) {
            self::databaseMode($app);
        }

        // Flush hooks are cheap and lazy: they do nothing unless the manager was actually used. The terminating
        // callback takes the application it is called on (Laravel injects it): under Octane that is the per-request
        // sandbox, where the SinkManager of that request lives, not the application that booted the module.
        $flushIn = static function (Application $current): void {
            if ($current->resolved(SinkManager::class)) {
                $current->make(SinkManager::class)->flush();
            }
        };
        $app->terminating(static function (Application $current) use ($flushIn): void {
            $flushIn($current);
        });
        $events = $app->make(Dispatcher::class);
        $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], static function () use ($app, $flushIn): void {
            $flushIn($app);
        });

        ModuleMigrations::wire($app, [self::migrationPath()], self::PUBLISH_TAG, in_array('database', $names, true));
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/2026_11_20_000050_create_mobile_push_logs_table.php';
    }

    /** @return list<string> */
    public static function sinkNames(Application $app): array
    {
        $configured = self::config($app)->get('mobile-push.log.sinks', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (! is_array($configured)) {
            throw new ConfigurationException('mobile-push.log.sinks must be an array or a comma-separated string such as "log,database,otel".');
        }

        $names = [];
        foreach ($configured as $name) {
            if (! is_string($name)) {
                throw new ConfigurationException('mobile-push.log.sinks must contain only sink names such as "log", "database", "otel".');
            }
            $name = strtolower(trim($name));
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function payloadLevel(Application $app): PayloadLevel
    {
        $level = self::config($app)->get('mobile-push.log.payload', 'none');
        $parsed = is_string($level) ? PayloadLevel::tryFrom(strtolower($level)) : null;

        return $parsed ?? throw new ConfigurationException('mobile-push.log.payload must be one of: none, keys, full.');
    }

    private static function databaseMode(Application $app): DatabaseMode
    {
        $mode = self::config($app)->get('mobile-push.log.database.mode', 'failures');
        $parsed = is_string($mode) ? DatabaseMode::tryFrom(strtolower($mode)) : null;

        return $parsed ?? throw new ConfigurationException('mobile-push.log.database.mode must be one of: all, failures, sampled.');
    }

    private static function makeSink(Application $app, string $name): LogSink
    {
        $custom = SinkManager::extensions()[$name] ?? null;
        if ($custom !== null) {
            return $custom($app);
        }

        return match ($name) {
            'log' => self::logSink($app),
            'database' => self::databaseSink($app),
            'otel' => self::otelSink($app),
            default => throw new ConfigurationException("Unknown log sink '{$name}'. Built-in sinks: ".implode(', ', self::BUILT_IN).'. Register others with SinkManager::extend().'),
        };
    }

    private static function logSink(Application $app): LogChannelSink
    {
        $channel = self::config($app)->get('mobile-push.log.channel');
        $logger = $app->make('log')->channel(is_string($channel) && $channel !== '' ? $channel : null);

        $levels = [];
        $configured = self::config($app)->get('mobile-push.log.levels', []);
        if (is_array($configured)) {
            foreach ($configured as $status => $level) {
                if (! is_string($status) || ! is_string($level) || ! in_array(strtolower($level), self::LEVELS, true)) {
                    throw new ConfigurationException('mobile-push.log.levels must map a SendStatus value to a PSR-3 level (debug, info, notice, warning, error, critical, alert, emergency).');
                }
                $levels[$status] = strtolower($level);
            }
        }

        return new LogChannelSink($logger, $levels);
    }

    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private static function databaseSink(Application $app): DatabaseSink
    {
        $config = self::config($app);
        $table = $config->get('mobile-push.log.database.table', 'mobile_push_logs');
        $connection = $config->get('mobile-push.log.database.connection');
        $rate = $config->get('mobile-push.log.database.sample_rate', 0.01);

        return new DatabaseSink(
            $app->make(ConnectionResolverInterface::class),
            is_string($table) && $table !== '' ? $table : 'mobile_push_logs',
            is_string($connection) && $connection !== '' ? $connection : null,
            self::databaseMode($app),
            is_numeric($rate) ? (float) $rate : 0.01,
            max(1, self::int($app, 'database.insert_chunk', 100)),
        );
    }

    private static function otelSink(Application $app): OpenTelemetrySink
    {
        if (! OpenTelemetrySink::isInstalled()) {
            throw new ConfigurationException("The 'otel' log sink needs the OpenTelemetry API: run `composer require open-telemetry/api`, or remove 'otel' from mobile-push.log.sinks.");
        }

        // Providers bound in the container win (tests, custom SDK wiring); otherwise the global ones the app configured.
        $tracers = $app->bound(TracerProviderInterface::class) ? $app->make(TracerProviderInterface::class) : Globals::tracerProvider();
        $meters = $app->bound(MeterProviderInterface::class) ? $app->make(MeterProviderInterface::class) : Globals::meterProvider();

        return new OpenTelemetrySink($tracers, $meters);
    }

    private static function int(Application $app, string $key, int $default): int
    {
        $value = self::config($app)->get('mobile-push.log.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function config(Application $app): Repository
    {
        return $app->make('config');
    }
}
