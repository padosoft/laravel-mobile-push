<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Policies;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCapGate;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\StoreFailurePolicy;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;

/**
 * Frequency cap and server-side badge counter. Needs the GuardStore (CacheModule), TokenIndex (PrivacyModule) and a
 * ClockInterface. The integration step takes the gates from PolicyGates and calls BadgeResolver per owner.
 *
 * Config (all optional, read with defaults):
 *  - mobile-push.frequency.enabled (bool, true)
 *  - mobile-push.frequency.limits (map bucket => {per_hour?, per_day?} | 'unlimited'; default bucket 'default' 5/h 20/d)
 *  - mobile-push.frequency.no_policy_bucket (string bucket name, default 'default'; null/'' = not capped)
 *  - mobile-push.frequency.on_store_failure ('allow' | 'deny', default 'allow')
 *  - mobile-push.badge.enabled (bool, true)
 *  - mobile-push.badge.ttl_days (int >= 1, 90)
 *  - mobile-push.badge.per_binding (bool, false)
 */
final class PoliciesModule implements Module
{
    public const DEFAULT_LIMITS = ['default' => ['per_hour' => 5, 'per_day' => 20]];

    public static function register(Application $app): void
    {
        $app->singleton(FrequencyLimits::class, static function (Application $app): FrequencyLimits {
            $table = self::config($app)->get('mobile-push.frequency.limits', self::DEFAULT_LIMITS);
            if (! is_array($table)) {
                throw new ConfigurationException('mobile-push.frequency.limits must be a map of bucket => limits.');
            }

            return FrequencyLimits::fromArray($table);
        });

        $app->singleton(FrequencyCounter::class, static fn (Application $app): FrequencyCounter => new FrequencyCounter(
            $app->make(GuardStore::class),
            $app->make(ClockInterface::class),
        ));

        $app->singleton(FrequencyCapGate::class, static function (Application $app): FrequencyCapGate {
            $config = self::config($app);
            $failure = $config->get('mobile-push.frequency.on_store_failure', 'allow');
            $policy = is_string($failure) ? StoreFailurePolicy::tryFrom(strtolower(trim($failure))) : null;
            if ($policy === null) {
                throw new ConfigurationException("mobile-push.frequency.on_store_failure must be 'allow' or 'deny'.");
            }
            $noPolicy = $config->get('mobile-push.frequency.no_policy_bucket', FrequencyLimits::DEFAULT);
            $noPolicy = is_string($noPolicy) && trim($noPolicy) !== '' ? trim($noPolicy) : null;

            return new FrequencyCapGate(
                $app->make(FrequencyCounter::class),
                $app->make(FrequencyLimits::class),
                $app->make(TokenIndex::class),
                $policy,
                $noPolicy,
            );
        });

        $app->singleton(PolicyGates::class, static fn (Application $app): PolicyGates => new PolicyGates(
            self::flag($app, 'mobile-push.frequency.enabled', true) ? $app->make(FrequencyCapGate::class) : null,
        ));

        $app->singleton(BadgeCounter::class, static function (Application $app): BadgeCounter {
            $days = self::config($app)->get('mobile-push.badge.ttl_days', 90);
            if (! is_numeric($days) || (int) $days < 1) {
                throw new ConfigurationException('mobile-push.badge.ttl_days must be a positive number of days.');
            }

            return new BadgeCounter($app->make(GuardStore::class), $app->make(ClockInterface::class), (int) $days * 86400);
        });

        $app->singleton(BadgeResolver::class, static fn (Application $app): BadgeResolver => new BadgeResolver(
            $app->make(BadgeCounter::class),
            self::flag($app, 'mobile-push.badge.per_binding', false),
            self::flag($app, 'mobile-push.badge.enabled', true),
        ));
    }

    public static function boot(Application $app): void {}

    private static function flag(Application $app, string $key, bool $default): bool
    {
        $value = self::config($app)->get($key, $default);

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    private static function config(Application $app): Repository
    {
        $config = $app->make('config');

        return $config;
    }
}
