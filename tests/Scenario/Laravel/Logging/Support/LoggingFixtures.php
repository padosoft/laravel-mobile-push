<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Logging\LogSink;

final class LoggingFixtures
{
    public static function binding(string $name = 'main'): BindingRef
    {
        return new BindingRef('default', $name, Provider::Fcm, 'demo-project', Environment::Production);
    }

    public static function result(string $token, SendStatus $status = SendStatus::Accepted, ?string $raw = null, ReasonCode $code = ReasonCode::None, ?Destination $destination = null): SendResult
    {
        return new SendResult(
            $destination ?? FcmToken::of($token),
            $status,
            new Reason($code, $raw),
            attempts: 1,
            providerMessageId: $status === SendStatus::Accepted ? 'projects/demo/messages/'.substr(md5($token), 0, 12) : null,
            httpStatus: $status === SendStatus::Accepted ? 200 : 400,
            deliveryId: 'dlv_'.substr(md5($token), 0, 10),
        );
    }

    public static function token(int $n): string
    {
        return 'tok'.str_pad((string) $n, 20, 'x', STR_PAD_LEFT).hash('sha256', (string) $n);
    }

    /** A sink that always fails and counts how often it was asked. */
    public static function brokenSink(int &$calls): LogSink
    {
        return new class($calls) implements LogSink
        {
            public function __construct(private int &$calls) {}

            public function write(\Padosoft\MobilePush\Laravel\Logging\LogRecord ...$records): void
            {
                $this->calls++;

                throw new \RuntimeException('database is down');
            }

            public function flush(): void {}
        };
    }

    /** Create the log table with the package migration (table and connection from config). */
    public static function migrate(): void
    {
        $migration = require \Padosoft\MobilePush\Laravel\Logging\LoggingModule::migrationPath();
        $migration->up();
    }

    /**
     * Configure sinks and a monolog TestHandler channel, then rebuild the manager.
     *
     * @param  array<string, mixed>  $config  extra mobile-push.log.* overrides, dotted keys relative to mobile-push.log
     */
    public static function configure(string|array $sinks, array $config = []): \Monolog\Handler\TestHandler
    {
        $handler = new \Monolog\Handler\TestHandler;
        app()->instance(\Monolog\Handler\TestHandler::class, $handler);

        config([
            'logging.channels.mp_test' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\TestHandler::class, 'level' => 'debug'],
            'mobile-push.log.sinks' => $sinks,
            'mobile-push.log.channel' => 'mp_test',
            'mobile-push.log.database.mode' => 'all',
        ]);
        foreach ($config as $key => $value) {
            config(['mobile-push.log.'.$key => $value]);
        }
        app()->forgetInstance(\Padosoft\MobilePush\Laravel\Logging\SinkManager::class);
        app()->forgetInstance(\Padosoft\MobilePush\Laravel\Logging\LogRecordFactory::class);

        return $handler;
    }

    public static function manager(): \Padosoft\MobilePush\Laravel\Logging\SinkManager
    {
        return app(\Padosoft\MobilePush\Laravel\Logging\SinkManager::class);
    }
}
