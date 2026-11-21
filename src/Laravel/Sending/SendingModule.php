<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Auth\SecretResolver;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\Curl\ConnectionReuse;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The synchronous send path: transport, guards, safe mode, retry policy, per-provider senders, the push manager
 * (singleton, also bound as PushDispatcher, facade MobilePush).
 *
 * Bindings:
 *  - Transport: the real CurlTransport, its pool registry capped by `transport.max_pools`, connection reuse from
 *    `transport.reuse` (auto | on | off; tests rebind FakeTransport with app()->instance(Transport::class, ...)
 *    before the first send);
 *  - Guards: breakers, throttle gates and rate buckets on the container's GuardStore, configured from
 *    `mobile-push.guards`, WITHOUT a rate ceiling (the manager's per-provider senders apply guards.rate.fcm and
 *    guards.rate.apns); breaker state is shared through the store, so any Guards instance reads the same state;
 *  - SafeModeGuard, RetryPolicy (from `mobile-push.retry` / `unknown_outcome`), SendingSettings;
 *  - Sender: a framework-free sender with those guards, for modules that bypass the manager (the manager attaches
 *    its observer per call with Sender::withObserver());
 *  - ConnectionFactory, Senders, PushManager (+ PushDispatcher);
 *  - EndpointCatalog for the application environment (bindIf, singleton).
 *
 * Per call the manager attaches two observers: SendingObserver (registry, send log, events) and OperationsObserver
 * (metrics and operational alerts of the health module, when it is installed).
 */
final class SendingModule implements Module
{
    public static function register(Application $app): void
    {
        $app->singleton(SendingSettings::class, static function (Application $app): SendingSettings {
            $config = self::config($app)->get('mobile-push', []);

            return SendingSettings::fromArray(is_array($config) ? $config : []);
        });

        // The pool registry of the process is capped by `transport.max_pools`; the shared process registry (default
        // cap) is kept when the value is the default, so every CurlTransport of the process still shares its pools.
        // `transport.reuse`: on = keep connections between sends; off = one private pool per send, closed when the send
        // ends; auto = on, except for a send that starts while a coroutine runtime (Swoole/OpenSwoole) hooks curl.
        // An invalid value throws ConfigurationException here, before any request.
        $app->singleton(Transport::class, static function (Application $app): Transport {
            $max = $app->make(SendingSettings::class)->transportMaxPools;
            $reuse = ConnectionReuse::fromConfig(self::config($app)->get('mobile-push.transport.reuse', 'auto'));

            return new CurlTransport($max === CurlPoolRegistry::DEFAULT_MAX_POOLS ? null : new CurlPoolRegistry($max), reuse: $reuse);
        });

        // The official endpoints for the application environment (overrides only outside production). Used by modules
        // that build their own clients on the driver's profiles (Live Activities channel management).
        $app->bindIf(EndpointCatalog::class, static fn (Application $app): EndpointCatalog => new EndpointCatalog((string) $app->environment()), true);

        $app->singleton(SafeModeGuard::class, static fn (Application $app): SafeModeGuard => new SafeModeGuard($app->make(TokenIndex::class)));

        // Not a singleton: other modules (ledger, jobs) read the CURRENT retry config each time they build a policy.
        $app->bind(RetryPolicy::class, static function (Application $app): RetryPolicy {
            $config = self::config($app)->get('mobile-push', []);

            return SendingSettings::fromArray(is_array($config) ? $config : [])->retry;
        });

        $app->singleton(Senders::class, static fn (Application $app): Senders => new Senders(
            $app->make(Transport::class),
            $app->make(GuardStore::class),
            $app->make(SafeModeGuard::class),
            $app->make(ClockInterface::class),
            $app->make(SendingSettings::class),
            $app->bound(Redactor::class) ? $app->make(Redactor::class) : null,
            self::logger($app),
        ));

        $app->singleton(Guards::class, static fn (Application $app): Guards => $app->make(Senders::class)->guards(null));

        $app->singleton(Sender::class, static fn (Application $app): Sender => new Sender(
            $app->make(Transport::class),
            $app->make(Guards::class),
            $app->make(SafeModeGuard::class),
            $app->make(ClockInterface::class),
            $app->make(RetryPolicy::class),
            redactor: $app->bound(Redactor::class) ? $app->make(Redactor::class) : null,
            logger: self::logger($app),
        ));

        $app->singleton(ConnectionFactory::class, static fn (Application $app): ConnectionFactory => new ConnectionFactory(
            $app->make(TokenStore::class),
            $app->make(ClockInterface::class),
            $app->make(Transport::class),
            $app->make(TokenIndex::class),
            $app->make(SendingSettings::class),
            (string) $app->environment(),
            static function (string $class) use ($app): SecretResolver {
                $resolver = $app->make($class);
                if (! $resolver instanceof SecretResolver) {
                    throw new ConfigurationException('The configured secret resolver does not implement SecretResolver.');
                }

                return $resolver;
            },
            static fn (string $abstract): mixed => $app->make($abstract),
        ));

        $app->singleton(PushManager::class, static fn (Application $app): PushManager => new PushManager(
            $app,
            $app->make(SendingSettings::class),
            $app->make(ConnectionFactory::class),
            $app->make(Senders::class),
            $app->make(TokenIndex::class),
        ));

        $app->alias(PushManager::class, PushDispatcher::class);
    }

    public static function boot(Application $app): void {}

    private static function config(Application $app): Repository
    {
        $config = $app->make('config');

        return $config;
    }

    private static function logger(Application $app): ?LoggerInterface
    {
        if (! $app->bound(LoggerInterface::class) && ! $app->bound('log')) {
            return null;
        }

        return $app->make(LoggerInterface::class);
    }
}
