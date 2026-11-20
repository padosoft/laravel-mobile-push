<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Contracts\Config\Repository;

/** Every config key of the health module, read once with explicit defaults. */
final readonly class HealthSettings
{
    public const DEFAULT_PATH = 'mobile-push/metrics';

    /**
     * @param  list<string>  $metricsMiddleware
     * @param  list<string>  $alertMail
     */
    public function __construct(
        public bool $metricsEnabled = true,
        public bool $prometheusEnabled = false,
        public string $prometheusPath = self::DEFAULT_PATH,
        public array $metricsMiddleware = [RequireMetricsToken::class],
        public ?string $prometheusToken = null,
        public int $maxSeries = 1000,
        public int $ledgerBacklogSeconds = 300,
        public int $ledgerBacklogFailedSeconds = 3600,
        public bool $durable = false,
        public string $mode = 'auto',
        public string $appEnvironment = 'production',
        public ?string $queueConnection = null,
        public ?int $queueTimeout = null,
        public bool $alertsEnabled = false,
        public array $alertMail = [],
        public ?string $alertSlackWebhook = null,
        public int $alertCooldownSeconds = 3600,
        public bool $alertSendInSimulate = false,
        public float $errorRateThreshold = 0.5,
        public int $errorRateMinSample = 50,
        public int $errorRateWindowSeconds = 300,
        public int $credentialWarnDays = 30,
        public bool $registerSpatieCheck = true,
        public string $cachePrefix = 'mobile-push',
    ) {}

    public static function fromConfig(Repository $config, string $appEnvironment): self
    {
        $string = static function (string $key, ?string $default) use ($config): ?string {
            $value = $config->get('mobile-push.'.$key, $default);

            return is_string($value) && $value !== '' ? $value : $default;
        };
        $int = static function (string $key, int $default) use ($config): int {
            $value = $config->get('mobile-push.'.$key, $default);

            return is_numeric($value) ? (int) $value : $default;
        };
        $bool = static function (string $key, bool $default) use ($config): bool {
            $value = $config->get('mobile-push.'.$key, $default);

            return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
        };
        $float = static function (string $key, float $default) use ($config): float {
            $value = $config->get('mobile-push.'.$key, $default);

            return is_numeric($value) ? (float) $value : $default;
        };
        $queueTimeout = $config->get('mobile-push.queue.timeout');

        return new self(
            metricsEnabled: $bool('metrics.enabled', true),
            prometheusEnabled: $bool('metrics.prometheus.enabled', false),
            prometheusPath: trim($string('metrics.prometheus.path', self::DEFAULT_PATH) ?? self::DEFAULT_PATH, '/'),
            metricsMiddleware: self::list($config, 'metrics.prometheus.middleware', [RequireMetricsToken::class]),
            prometheusToken: $string('metrics.prometheus.token', null),
            maxSeries: max(10, $int('metrics.max_series', 1000)),
            ledgerBacklogSeconds: max(1, $int('health.ledger_backlog_seconds', 300)),
            ledgerBacklogFailedSeconds: max(1, $int('health.ledger_backlog_failed_seconds', 3600)),
            durable: $string('delivery.profile', 'light') === 'durable',
            mode: $string('mode', 'auto') ?? 'auto',
            appEnvironment: $appEnvironment,
            queueConnection: $string('queue.connection', null),
            queueTimeout: is_numeric($queueTimeout) ? (int) $queueTimeout : null,
            alertsEnabled: $bool('alerts.enabled', false),
            alertMail: self::list($config, 'alerts.mail', []),
            alertSlackWebhook: $string('alerts.slack_webhook', null),
            alertCooldownSeconds: max(1, $int('alerts.cooldown_seconds', 3600)),
            alertSendInSimulate: $bool('alerts.send_in_simulate', false),
            errorRateThreshold: min(1.0, max(0.0, $float('alerts.error_rate.threshold', 0.5))),
            errorRateMinSample: max(1, $int('alerts.error_rate.min_sample', 50)),
            errorRateWindowSeconds: max(60, $int('alerts.error_rate.window_seconds', 300)),
            credentialWarnDays: max(0, $int('credentials.warn_days', 30)),
            registerSpatieCheck: $bool('health.spatie.register', true),
            cachePrefix: $string('cache.prefix', 'mobile-push') ?? 'mobile-push',
        );
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function list(Repository $config, string $key, array $default): array
    {
        $value = $config->get('mobile-push.'.$key, $default);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $value), static fn (string $v): bool => $v !== ''));
    }
}
