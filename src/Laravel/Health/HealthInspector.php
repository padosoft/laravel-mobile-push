<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Transport\Curl\CurlCapabilities;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Psr\Clock\ClockInterface;

/**
 * Liveness (can the package run in this process?) and readiness (can it do its job now?). Never sends a request
 * to a provider and never throws: a check that crashes is reported as failed with the exception CLASS only.
 *
 * A live probe (a real request to APNs/FCM) is deliberately NOT part of this class, because a health check runs
 * automatically (schedulers, orchestrators, spatie/laravel-health) and a probe is a real request that can be counted,
 * billed or rate limited. It belongs to an explicit `mobile-push:doctor --live`.
 */
final class HealthInspector
{
    private const REQUIRED_EXTENSIONS = ['curl', 'openssl', 'json'];

    /**
     * @param  \Closure(): ?DeliveryStore  $ledger  null when the durable profile is off
     * @param  \Closure(): ?SinkManager  $sinks
     * @param  \Closure(?string): array{connection: ?string, driver: ?string, retry_after: ?int}  $queue  resolves a queue connection (null = default)
     * @param  list<string>  $configIssues  problems found while reading the configuration (for instance "connections is not an array")
     */
    public function __construct(
        private readonly HealthSettings $settings,
        private readonly ClockInterface $clock,
        private readonly ConnectionCredentialsSource $credentials,
        private readonly CredentialCheck $credentialCheck,
        private readonly Repository $cache,
        private readonly MetricsRecorder $recorder,
        private readonly \Closure $ledger,
        private readonly \Closure $sinks,
        private readonly \Closure $queue,
        private readonly Redactor $redactor,
        private readonly array $configIssues = [],
    ) {}

    public function inspect(): HealthReport
    {
        $liveness = [
            $this->guard('php_extensions', fn (): HealthCheckResult => $this->extensions()),
            $this->guard('curl_capabilities', fn (): HealthCheckResult => $this->curl()),
            $this->guard('config', fn (): HealthCheckResult => $this->config()),
        ];

        $readiness = [];
        foreach ($this->guardMany('credentials', fn (): array => $this->credentialChecks()) as $check) {
            $readiness[] = $check;
        }
        $readiness[] = $this->guard('cache', fn (): HealthCheckResult => $this->cache());
        if ($this->settings->durable) {
            $readiness[] = $this->guard('ledger', fn (): HealthCheckResult => $this->ledger());
        }
        $readiness[] = $this->guard('log_sinks', fn (): HealthCheckResult => $this->logSinks());
        $readiness[] = $this->guard('guards', fn (): HealthCheckResult => $this->guards());
        $readiness[] = $this->guard('queue', fn (): HealthCheckResult => $this->queue());
        $readiness[] = $this->guard('execution_mode', fn (): HealthCheckResult => $this->executionMode());

        return new HealthReport($liveness, $readiness, $this->clock->now());
    }

    /** The effective execution mode, or null when the configured value is invalid. */
    public function mode(): ?ExecutionMode
    {
        try {
            return (new ModeResolver)->resolve($this->settings->mode, $this->settings->appEnvironment);
        } catch (ConfigurationException) {
            return null;
        }
    }

    // ---- liveness ----------------------------------------------------------------------------------------------

    private function extensions(): HealthCheckResult
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $e): bool => ! extension_loaded($e)));
        if ($missing !== []) {
            return HealthCheckResult::failed('php_extensions', 'Missing PHP extension(s): '.implode(', ', $missing).'. Install and enable them (for example apt install php-curl php-xml) and restart PHP-FPM / the queue workers.', ['missing' => implode(',', $missing)]);
        }

        return HealthCheckResult::ok('php_extensions', 'Required PHP extensions are loaded ('.implode(', ', self::REQUIRED_EXTENSIONS).').');
    }

    private function curl(): HealthCheckResult
    {
        $caps = CurlCapabilities::detect();
        if (! $caps->extensionLoaded) {
            return HealthCheckResult::failed('curl_capabilities', 'The PHP curl extension is not loaded. Install ext-curl and restart PHP.');
        }
        $data = ['libcurl' => $caps->libcurlVersion, 'http2' => $caps->http2, 'blob_options' => $caps->blobOptions];
        if ($caps->missingConstants !== [] || $caps->libcurlVersionNumber < CurlCapabilities::MIN_LIBCURL || ! $caps->ssl) {
            return HealthCheckResult::failed('curl_capabilities', sprintf('libcurl %s is too old or built without TLS for the push transport. Use PHP built against libcurl >= 7.68 with OpenSSL (the official PHP Docker images are fine).', $caps->libcurlVersion), $data);
        }
        if (! $caps->http2) {
            return HealthCheckResult::failed('curl_capabilities', sprintf('libcurl %s has no HTTP/2 support, which APNs requires. Install a libcurl built with nghttp2 (curl -V must list HTTP2).', $caps->libcurlVersion), $data);
        }
        if (! $caps->blobOptions) {
            return HealthCheckResult::warning('curl_capabilities', sprintf('libcurl %s cannot take client certificates in memory (needs >= 7.71). Token-based (.p8) APNs keys and FCM still work; certificate-based APNs does not. Upgrade libcurl or use a .p8 key.', $caps->libcurlVersion), $data);
        }

        return HealthCheckResult::ok('curl_capabilities', sprintf('libcurl %s with HTTP/2 and TLS.', $caps->libcurlVersion), $data);
    }

    private function config(): HealthCheckResult
    {
        $issues = $this->configIssues;
        if ($this->mode() === null) {
            $issues[] = 'mobile-push.mode must be "auto", "live" or "simulate"';
        }
        if ($this->settings->ledgerBacklogFailedSeconds < $this->settings->ledgerBacklogSeconds) {
            $issues[] = 'health.ledger_backlog_failed_seconds is lower than health.ledger_backlog_seconds';
        }
        if ($issues !== []) {
            return HealthCheckResult::failed('config', 'Configuration problem(s): '.implode('; ', $issues).'. Fix config/mobile-push.php (or the matching MOBILE_PUSH_* variables) and clear the config cache.');
        }

        return HealthCheckResult::ok('config', 'The configuration parses.');
    }

    // ---- readiness ---------------------------------------------------------------------------------------------

    /** @return list<HealthCheckResult> */
    private function credentialChecks(): array
    {
        if (! $this->credentials->available()) {
            return [HealthCheckResult::notVerifiable('credentials', 'No connection source is bound, so credentials cannot be inspected from here. Run mobile-push:credentials:check once the push manager is installed.')];
        }
        $connections = $this->credentials->connections();
        if ($connections === []) {
            return [HealthCheckResult::warning('credentials', 'No connection is configured. Add one under mobile-push.connections.')];
        }

        $checks = [];
        foreach ($connections as $connection) {
            $name = 'credentials.'.$connection->binding->name;
            if ($connection->error !== null) {
                $checks[] = HealthCheckResult::failed($name, 'The credentials of this connection cannot be read: '.$this->redactor->string($connection->error).'. Check the secret path or value in the connection configuration.', $this->bindingData($connection));

                continue;
            }
            $result = $connection->evaluate($this->credentialCheck);
            $data = $this->bindingData($connection);
            if ($connection->certificate !== null) {
                $data['days_remaining'] = $connection->certificate->daysRemaining($this->clock->now());
            }
            $problems = [];
            foreach ($result->findings as $finding) {
                if ($finding->severity !== Severity::Ok) {
                    $problems[] = $this->redactor->string($finding->message);
                }
            }
            $checks[] = match ($result->worst()) {
                Severity::Error => HealthCheckResult::failed($name, implode(' ', $problems), $data),
                Severity::Warning => HealthCheckResult::warning($name, implode(' ', $problems), $data),
                Severity::Ok => HealthCheckResult::ok($name, 'Credentials parse and are valid.', $data),
            };
        }

        return $checks;
    }

    /** @return array<string, scalar|null> */
    private function bindingData(ConnectionCredentials $connection): array
    {
        return ['provider' => $connection->binding->provider->value, 'environment' => $connection->binding->environment->value];
    }

    private function cache(): HealthCheckResult
    {
        $key = $this->settings->cachePrefix.':health:probe';
        $value = bin2hex(random_bytes(6));

        try {
            $this->cache->put($key, $value, 30);
            $read = $this->cache->get($key);
            $this->cache->forget($key);
        } catch (\Throwable $e) {
            return HealthCheckResult::failed('cache', sprintf('The cache store is not reachable (%s). Tokens, breakers and rate limits cannot be shared and metrics are not recorded. Check the cache server and mobile-push.cache.store (MOBILE_PUSH_CACHE_STORE).', $e::class));
        }
        if ($read !== $value) {
            return HealthCheckResult::failed('cache', 'The cache store did not return what was written (is it the "null" store?). Point mobile-push.cache.store at a real store such as redis.');
        }

        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return HealthCheckResult::warning('cache', 'The cache store cannot take locks, so token refresh and breaker updates are not atomic across workers. Use redis, memcached, database or dynamodb.');
        }
        try {
            $lock = $store->lock($this->settings->cachePrefix.':health:lock', 5);
            if (! $lock->get()) {
                return HealthCheckResult::warning('cache', 'The cache lock probe is held by another process right now; try again.');
            }
            $lock->release();
        } catch (\Throwable $e) {
            return HealthCheckResult::failed('cache', sprintf('The cache store reads and writes but locking failed (%s). Check the cache server permissions (Lua/SET NX support).', $e::class));
        }

        return HealthCheckResult::ok('cache', 'The cache store is reachable and lock-capable.', ['store' => $store::class]);
    }

    private function ledger(): HealthCheckResult
    {
        $store = ($this->ledger)();
        if ($store === null) {
            return HealthCheckResult::notVerifiable('ledger', 'The durable profile is on but no delivery store is bound.');
        }

        try {
            $health = $store->health();
        } catch (\Throwable $e) {
            return HealthCheckResult::failed('ledger', sprintf('The delivery ledger is not reachable (%s). Check the database connection and that the mobile_push_* migrations have run.', $e::class));
        }

        $age = $health->oldestDueAgeMs === null ? 0 : intdiv($health->oldestDueAgeMs, 1000);
        $data = [
            'due' => $health->due, 'pending' => $health->pending, 'deferred' => $health->deferred,
            'in_flight' => $health->inFlight, 'unknown' => $health->unknown, 'oldest_due_age_seconds' => $age,
        ];
        $summary = sprintf('due %d, deferred %d, in flight %d, unknown outcome %d, oldest due %ds', $health->due, $health->deferred, $health->inFlight, $health->unknown, $age);

        if ($age >= $this->settings->ledgerBacklogFailedSeconds) {
            return HealthCheckResult::failed('ledger', sprintf('The oldest due delivery is %ds old (limit %ds): the relay is not keeping up or is stopped (%s). Start or scale the queue workers / scheduler that run mobile-push:relay.', $age, $this->settings->ledgerBacklogFailedSeconds, $summary), $data);
        }
        if ($age >= $this->settings->ledgerBacklogSeconds) {
            return HealthCheckResult::warning('ledger', sprintf('A backlog is building: the oldest due delivery is %ds old (warning at %ds) (%s). Check the relay workers and provider throttling.', $age, $this->settings->ledgerBacklogSeconds, $summary), $data);
        }

        return HealthCheckResult::ok('ledger', 'The ledger is reachable and not backed up ('.$summary.').', $data);
    }

    private function logSinks(): HealthCheckResult
    {
        $manager = ($this->sinks)();
        if ($manager === null) {
            return HealthCheckResult::ok('log_sinks', 'No log sink is configured.');
        }
        $stats = $manager->stats();
        $problems = [];
        $failed = $dropped = 0;
        foreach ($stats['sinks'] as $name => $sink) {
            $failed += $sink['failed'];
            $dropped += $sink['dropped'];
            if ($sink['failed'] > 0 || $sink['dropped'] > 0) {
                $problems[] = sprintf('%s (failed %d, dropped %d)', $name, $sink['failed'], $sink['dropped']);
            }
        }
        $data = ['failed' => $failed, 'dropped' => $dropped, 'buffered' => $stats['buffered'], 'record_errors' => $stats['record_errors']];
        if ($problems !== [] || $stats['record_errors'] > 0) {
            return HealthCheckResult::warning('log_sinks', 'Log sinks are losing records: '.implode(', ', $problems).'. Sending is not affected. Check the log channel / database / OpenTelemetry endpoint, or raise mobile-push.log.buffer.max.', $data);
        }

        return HealthCheckResult::ok('log_sinks', 'Log sinks are writing without failures or drops (in this process).', $data);
    }

    private function guards(): HealthCheckResult
    {
        if (! $this->recorder->enabled()) {
            return HealthCheckResult::notVerifiable('guards', 'Metrics are disabled, so open circuits and throttled accounts are not tracked. Set mobile-push.metrics.enabled to true.');
        }
        $open = $this->recorder->openCircuits();
        $throttled = $this->recorder->throttledAccounts();
        $data = ['open_circuits' => count($open), 'throttled_accounts' => count($throttled)];
        if ($open === [] && $throttled === []) {
            return HealthCheckResult::ok('guards', 'No circuit is open and no account is throttled.', $data);
        }

        $names = array_map(static fn (array $l): string => ($l['connection'] ?? '?').'/'.($l['guard'] ?? '?'), array_slice($open, 0, 5));
        $authOpen = array_filter($open, static fn (array $l): bool => ($l['guard'] ?? '') === 'auth');
        $message = sprintf('%d open circuit(s)%s, %d throttled account(s). An open auth circuit means the provider is refusing the credentials: check or rotate them, then the circuit closes after a successful probe. Throttles end by themselves.', count($open), $names === [] ? '' : ' ['.implode(', ', $names).']', count($throttled));

        return $authOpen !== [] ? HealthCheckResult::failed('guards', $message, $data) : HealthCheckResult::warning('guards', $message, $data);
    }

    private function queue(): HealthCheckResult
    {
        $timeout = $this->settings->queueTimeout;
        $info = ($this->queue)($this->settings->queueConnection);
        if ($timeout === null || $info['connection'] === null) {
            return HealthCheckResult::notVerifiable('queue', 'Queue timeouts cannot be verified: set mobile-push.queue.timeout (the job timeout) and mobile-push.queue.connection to compare it with the connection retry_after.');
        }
        if ($info['driver'] === 'sync') {
            return HealthCheckResult::ok('queue', 'The queue connection is synchronous: there is no retry_after to collide with.');
        }
        if ($info['retry_after'] === null) {
            return HealthCheckResult::notVerifiable('queue', sprintf('The queue connection "%s" has no numeric retry_after (driver %s), so it cannot be compared with the job timeout.', $info['connection'], $info['driver'] ?? 'unknown'));
        }
        $data = ['job_timeout' => $timeout, 'retry_after' => $info['retry_after']];
        if ($info['retry_after'] <= $timeout) {
            return HealthCheckResult::failed('queue', sprintf('retry_after (%ds) is not greater than the job timeout (%ds) on connection "%s": a slow job is handed to a second worker while the first is still running, which duplicates pushes. Raise retry_after above the timeout (at least timeout + 10s) in config/queue.php.', $info['retry_after'], $timeout, $info['connection']), $data);
        }

        return HealthCheckResult::ok('queue', 'The queue retry_after is greater than the job timeout.', $data);
    }

    private function executionMode(): HealthCheckResult
    {
        $mode = $this->mode();
        if ($mode === null) {
            return HealthCheckResult::failed('execution_mode', 'mobile-push.mode is invalid: use "auto", "live" or "simulate".');
        }
        $production = $this->settings->appEnvironment === ModeResolver::PRODUCTION;
        $data = ['mode' => $mode->value, 'app_environment' => $this->settings->appEnvironment];

        if ($mode === ExecutionMode::Simulate && $production) {
            return HealthCheckResult::warning('execution_mode', 'SIMULATE MODE IN PRODUCTION: no push is being sent to any provider. Set mobile-push.mode to "live" (MOBILE_PUSH_MODE=live) or "auto" and clear the config cache.', $data);
        }
        if ($mode === ExecutionMode::Live && ! $production) {
            return HealthCheckResult::warning('execution_mode', sprintf('LIVE MODE in the "%s" environment: real pushes can reach real devices. Use "auto" or "simulate" unless this is intended.', $this->settings->appEnvironment), $data);
        }

        return HealthCheckResult::ok('execution_mode', sprintf('Execution mode is %s in the "%s" environment.', $mode->value, $this->settings->appEnvironment), $data);
    }

    // ---- plumbing ----------------------------------------------------------------------------------------------

    /** @param  \Closure(): HealthCheckResult  $check */
    private function guard(string $name, \Closure $check): HealthCheckResult
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            return HealthCheckResult::failed($name, sprintf('The check crashed (%s). This is a bug or a broken dependency: look at the application log.', $e::class));
        }
    }

    /**
     * @param  \Closure(): list<HealthCheckResult>  $checks
     * @return list<HealthCheckResult>
     */
    private function guardMany(string $name, \Closure $checks): array
    {
        try {
            return $checks();
        } catch (\Throwable $e) {
            return [HealthCheckResult::failed($name, sprintf('The check crashed (%s). This is a bug or a broken dependency: look at the application log.', $e::class))];
        }
    }
}
