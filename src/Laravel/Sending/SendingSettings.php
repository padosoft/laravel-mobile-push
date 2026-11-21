<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;

/**
 * The top-level sending configuration (`config('mobile-push')` minus the connections), parsed and validated once.
 * Every error names the key and the allowed values; nothing secret is ever read here.
 */
final readonly class SendingSettings
{
    public const MODES = ['auto', 'live', 'simulate'];

    public const PER_RESULT = ['auto', 'always', 'never'];

    public const PROFILES = ['light', 'durable'];

    /**
     * @param  list<string>|null  $routes
     * @param  array{window: int, max_connections: int, connect_timeout_ms: int, timeout_ms: int, max_idle_seconds: int, proxy: string|null, ca_bundle: string|null}  $transport
     */
    public function __construct(
        public string $scope = Scope::DEFAULT,
        public ?string $defaultConnection = null,
        public string $mode = 'auto',
        public RetryPolicy $retry = new RetryPolicy,
        public int $apnsServerErrorRetryDelay = 900,
        public int $apnsDeviceRateLimitDelay = 60,
        public int $fcmThrottledMinDelay = 60,
        public ?int $fcmRatePerMinute = 500_000,
        public ?int $apnsRatePerMinute = null,
        public float $reservedShare = 0.2,
        public ?int $burst = null,
        public int $authThreshold = 5,
        public int $authCooldown = 60,
        public int $qualityMinSample = 200,
        public float $qualityThreshold = 0.5,
        public int $qualityCooldown = 300,
        public string $deliveryProfile = 'light',
        public string $perResultEvents = 'auto',
        public ?array $routes = null,
        public int $connectionCacheSize = 64,
        public array $transport = self::TRANSPORT_DEFAULTS,
        /** Cap of the per-process transport pool registry (transport.max_pools). */
        public int $transportMaxPools = self::DEFAULT_MAX_POOLS,
    ) {}

    public const DEFAULT_MAX_POOLS = CurlPoolRegistry::DEFAULT_MAX_POOLS;

    public const TRANSPORT_DEFAULTS = [
        'window' => 100,
        'max_connections' => 1,
        'connect_timeout_ms' => 5000,
        'timeout_ms' => 30000,
        'max_idle_seconds' => 3600,
        'proxy' => null,
        'ca_bundle' => null,
    ];

    /**
     * @param  array<mixed>  $config  the whole `mobile-push` config array
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $c = new ConfigReader($config, 'mobile-push');

        $scope = $c->string('scope') ?? Scope::DEFAULT;
        try {
            $scope = Scope::normalize($scope);
        } catch (\InvalidArgumentException) {
            throw new ConfigurationException('mobile-push.scope may contain only letters, digits, ".", "_", ":" and "-" (max 64 characters).');
        }

        $unknown = $c->choice('unknown_outcome.retry', array_map(static fn (UnknownOutcomeRetry $u): string => $u->value, UnknownOutcomeRetry::cases()), 'replaceable');

        $retry = new RetryPolicy(
            maxAttempts: $c->int('retry.max_attempts', 5, 1),
            maxElapsedSeconds: $c->int('retry.max_elapsed', 3600, 0),
            backoffBaseMs: $c->int('retry.backoff_base_ms', 1000, 0),
            backoffMaxMs: $c->int('retry.backoff_max_ms', 300_000, 0),
            jitter: $c->float('retry.jitter', 0.2, 0.0, 1.0),
            unknownOutcome: UnknownOutcomeRetry::from($unknown),
            inlineThresholdMs: $c->int('retry.inline_threshold_ms', 0, 0),
        );

        $routes = $c->stringList('routes');

        $transport = [
            'window' => $c->int('transport.window', 100, 1, 10_000),
            'max_connections' => $c->int('transport.max_connections', 1, 1),
            'connect_timeout_ms' => $c->int('transport.connect_timeout_ms', 5000, 1),
            'timeout_ms' => $c->int('transport.timeout_ms', 30_000, 1),
            'max_idle_seconds' => $c->int('transport.max_idle_seconds', 3600, 1),
            'proxy' => $c->string('transport.proxy'),
            'ca_bundle' => $c->string('transport.ca_bundle'),
        ];

        return new self(
            scope: $scope,
            defaultConnection: $c->string('default'),
            mode: $c->choice('mode', self::MODES, 'auto'),
            retry: $retry,
            apnsServerErrorRetryDelay: $c->int('apns.server_error_retry_delay', 900, 0),
            apnsDeviceRateLimitDelay: $c->int('apns.device_rate_limit_delay', 60, 0),
            fcmThrottledMinDelay: $c->int('fcm.throttled_min_delay', 60, 0),
            fcmRatePerMinute: $c->nullableInt('guards.rate.fcm', 500_000, 1),
            apnsRatePerMinute: $c->nullableInt('guards.rate.apns', null, 1),
            reservedShare: $c->float('guards.rate.reserved_share', 0.2, 0.0, 1.0),
            burst: $c->nullableInt('guards.rate.burst', null, 1),
            authThreshold: $c->int('guards.auth.threshold', 5, 1),
            authCooldown: $c->int('guards.auth.cooldown', 60, 1),
            qualityMinSample: $c->int('guards.destination_quality.min_sample', 200, 1),
            qualityThreshold: $c->float('guards.destination_quality.threshold', 0.5, 0.01, 0.99),
            qualityCooldown: $c->int('guards.destination_quality.cooldown', 300, 1),
            deliveryProfile: $c->choice('delivery.profile', self::PROFILES, 'light'),
            perResultEvents: $c->choice('events.per_result', self::PER_RESULT, 'auto'),
            routes: $routes,
            connectionCacheSize: $c->int('connection_cache.size', 64, 1, 100_000),
            transport: $transport,
            transportMaxPools: $c->int('transport.max_pools', self::DEFAULT_MAX_POOLS, 1),
        );
    }
}
