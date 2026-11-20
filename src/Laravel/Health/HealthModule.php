<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Spatie\Health\Health;

/**
 * Health checks, Prometheus metrics and operational alerts.
 *
 * Bindings (all lazy singletons): HealthSettings, MetricsRecorder, PrometheusExporter, HealthInspector,
 * CredentialCheck (bindIf), ConnectionCredentialsSource (bindIf, default Null: the integration step binds the real one),
 * CredentialMonitor, OpsAlerter, RequireMetricsToken.
 *
 * Route: GET `mobile-push.metrics.prometheus.path` only when `mobile-push.metrics.prometheus.enabled` is true, behind the
 * configured middleware (default RequireMetricsToken, which refuses everything until a token is configured).
 *
 * Config keys (all optional, defaults in HealthSettings): metrics.{enabled, max_series, prometheus.{enabled, path,
 * middleware, token}}, health.{ledger_backlog_seconds, ledger_backlog_failed_seconds, spatie.register},
 * alerts.{enabled, mail, slack_webhook, cooldown_seconds, send_in_simulate, error_rate.{threshold, min_sample,
 * window_seconds}}, credentials.warn_days. Read from other modules: mode, delivery.profile, cache.{store, prefix},
 * queue.{connection, timeout}.
 */
final class HealthModule implements Module
{
    public const ROUTE_NAME = 'mobile-push.metrics';

    private const SPATIE_CHECK = 'Spatie\Health\Checks\Check';

    private const SPATIE_HEALTH = 'Spatie\Health\Health';

    public static function register(Application $app): void
    {
        $app->singleton(HealthSettings::class, static fn (Application $app): HealthSettings => HealthSettings::fromConfig(self::config($app), $app->environment()));

        $app->singleton(MetricsRecorder::class, static function (Application $app): MetricsRecorder {
            $settings = $app->make(HealthSettings::class);

            return new MetricsRecorder(
                self::cache($app),
                $app->make(ClockInterface::class),
                $settings->metricsEnabled,
                $settings->cachePrefix,
                $settings->maxSeries,
                $settings->errorRateWindowSeconds,
            );
        });

        $app->singleton(PrometheusExporter::class, static fn (Application $app): PrometheusExporter => new PrometheusExporter(
            $app->make(MetricsRecorder::class),
            self::ledgerProvider($app),
            self::sinkProvider($app),
        ));

        $app->bindIf(ConnectionCredentialsSource::class, static fn (): ConnectionCredentialsSource => new NullConnectionCredentialsSource);

        $app->bindIf(CredentialCheck::class, static fn (Application $app): CredentialCheck => new CredentialCheck(
            $app->make(ClockInterface::class),
            $app->make(HealthSettings::class)->credentialWarnDays,
        ));

        $app->singleton(HealthInspector::class, static fn (Application $app): HealthInspector => new HealthInspector(
            $app->make(HealthSettings::class),
            $app->make(ClockInterface::class),
            $app->make(ConnectionCredentialsSource::class),
            $app->make(CredentialCheck::class),
            self::cache($app),
            $app->make(MetricsRecorder::class),
            self::ledgerProvider($app),
            self::sinkProvider($app),
            static fn (?string $name): array => self::queueInfo($app, $name),
            $app->make(Redactor::class),
            self::configIssues($app),
        ));

        $app->singleton(OpsAlerter::class, static function (Application $app): OpsAlerter {
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return new OpsAlerter(
                $app->make(HealthSettings::class),
                $app->make(ClockInterface::class),
                self::cache($app),
                $app->make(Redactor::class),
                $logger instanceof LoggerInterface ? $logger : null,
                $app->make(MetricsRecorder::class),
                self::ledgerProvider($app),
                self::sinkProvider($app),
            );
        });

        $app->singleton(CredentialMonitor::class, static fn (Application $app): CredentialMonitor => new CredentialMonitor(
            $app->make(ConnectionCredentialsSource::class),
            $app->make(CredentialCheck::class),
            $app->make(OpsAlerter::class),
            $app->make(ClockInterface::class),
            $app->make(Redactor::class),
        ));
    }

    public static function boot(Application $app): void
    {
        $settings = $app->make(HealthSettings::class);

        if ($settings->prometheusEnabled && ! $app->routesAreCached()) {
            $router = $app->make('router');
            $router->get($settings->prometheusPath === '' ? HealthSettings::DEFAULT_PATH : $settings->prometheusPath, MetricsController::class)
                ->middleware($settings->metricsMiddleware)
                ->name(self::ROUTE_NAME);
        }

        // The check class is referenced by name only after the package is known to be present.
        if ($settings->registerSpatieCheck && class_exists(self::SPATIE_CHECK) && class_exists(self::SPATIE_HEALTH) && $app->bound(self::SPATIE_HEALTH)) {
            $health = $app->make(Health::class);
            $already = $health->registeredChecks()->contains(static fn (mixed $check): bool => $check instanceof MobilePushHealthCheck);
            if (! $already) {
                $health->checks([MobilePushHealthCheck::new()]);
            }
        }
    }

    private static function config(Application $app): Config
    {
        $config = $app->make('config');

        return $config;
    }

    private static function cache(Application $app): Repository
    {
        $name = self::config($app)->get('mobile-push.cache.store');
        $store = $app->make('cache')->store(is_string($name) && $name !== '' ? $name : null);

        if (! $store instanceof Repository) {
            throw new ConfigurationException('mobile-push.cache.store must name a cache store defined in config/cache.php.');
        }

        return $store;
    }

    /** @return \Closure(): ?DeliveryStore */
    private static function ledgerProvider(Application $app): \Closure
    {
        return static function () use ($app): ?DeliveryStore {
            if (! $app->make(HealthSettings::class)->durable || ! $app->bound(DeliveryStore::class)) {
                return null;
            }

            return $app->make(DeliveryStore::class);
        };
    }

    /** @return \Closure(): ?SinkManager */
    private static function sinkProvider(Application $app): \Closure
    {
        return static function () use ($app): ?SinkManager {
            if (! $app->bound(SinkManager::class)) {
                return null;
            }

            return $app->make(SinkManager::class);
        };
    }

    /** @return array{connection: ?string, driver: ?string, retry_after: ?int} */
    private static function queueInfo(Application $app, ?string $name): array
    {
        $config = self::config($app);
        $connection = $name ?? $config->get('queue.default');
        if (! is_string($connection) || $connection === '') {
            return ['connection' => null, 'driver' => null, 'retry_after' => null];
        }
        $driver = $config->get('queue.connections.'.$connection.'.driver');
        $retryAfter = $config->get('queue.connections.'.$connection.'.retry_after');

        return [
            'connection' => $connection,
            'driver' => is_string($driver) ? $driver : null,
            'retry_after' => is_numeric($retryAfter) ? (int) $retryAfter : null,
        ];
    }

    /** @return list<string> */
    private static function configIssues(Application $app): array
    {
        $issues = [];
        $connections = self::config($app)->get('mobile-push.connections', []);
        if (! is_array($connections)) {
            $issues[] = 'mobile-push.connections must be an array';
        }

        return $issues;
    }
}
