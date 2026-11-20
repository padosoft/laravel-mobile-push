<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared state of the package on a Laravel cache store.
 *
 * Config: `mobile-push.cache.store` (null = application default store), `mobile-push.cache.prefix` (default
 * `mobile-push`). Needs PrivacyModule (TokenCipher) and a ClockInterface binding.
 */
final class CacheModule implements Module
{
    public static function register(Application $app): void
    {
        $app->singleton(CacheHealth::class, static function (Application $app): CacheHealth {
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return new CacheHealth($logger instanceof LoggerInterface ? $logger : null, $app->make(ClockInterface::class));
        });

        $app->singleton(TokenStore::class, static fn (Application $app): TokenStore => new CacheTokenStore(
            self::repository($app),
            $app->make(TokenCipher::class),
            $app->make(CacheHealth::class),
            self::prefix($app),
        ));

        $app->singleton(GuardStore::class, static fn (Application $app): GuardStore => new CacheGuardStore(
            self::repository($app),
            $app->make(CacheHealth::class),
            self::prefix($app),
        ));
    }

    public static function boot(Application $app): void {}

    private static function repository(Application $app): Repository
    {
        $name = self::config($app)->get('mobile-push.cache.store');
        $store = self::cacheManager($app)->store(is_string($name) && $name !== '' ? $name : null);

        if (! $store instanceof Repository) {
            throw new ConfigurationException('mobile-push.cache.store must name a cache store defined in config/cache.php.');
        }

        return $store;
    }

    private static function config(Application $app): Config
    {
        $config = $app->make('config');

        return $config;
    }

    private static function cacheManager(Application $app): CacheManager
    {
        $manager = $app->make('cache');

        return $manager;
    }

    private static function prefix(Application $app): string
    {
        $prefix = self::config($app)->get('mobile-push.cache.prefix', 'mobile-push');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'mobile-push';
    }
}
