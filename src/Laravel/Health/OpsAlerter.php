<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Notification;
use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Operational alerts through Laravel notifications to the routes in config (mail addresses, a Slack webhook).
 *
 *  - Every alert has a stable key (`kind:subject`) and a cooldown (cache-backed `Repository::add`, atomic): the same
 *    condition alerts at most once per `alerts.cooldown_seconds`. If the cache is down an in-process cooldown is used,
 *    so a broken cache does not turn into an alert storm. A circuit that closes re-arms its own alert.
 *  - Title, body and context pass through Redactor before they reach any channel; the alert text is built from public
 *    facts (connection name, counts, ages), never from tokens or provider responses.
 *  - Safe mode: in simulate mode an alert is written to the log instead of being sent, unless
 *    `alerts.send_in_simulate` is true.
 *
 * Entry points for the integration step: guardTransition() on every GuardTransition, credentialFindings() after a
 * credential check, evaluate() from a scheduler (every minute or so).
 */
final class OpsAlerter
{
    /** @var array<string, int> in-process cooldown fallback: key => expires at */
    private array $localCooldown = [];

    /** @var array<string, int> */
    private array $sinkFailedSeen = [];

    /**
     * @param  \Closure(): ?DeliveryStore  $ledger
     * @param  \Closure(): ?SinkManager  $sinks
     */
    public function __construct(
        private readonly HealthSettings $settings,
        private readonly ClockInterface $clock,
        private readonly Repository $cache,
        private readonly Redactor $redactor,
        private readonly ?LoggerInterface $logger,
        private readonly MetricsRecorder $recorder,
        private readonly \Closure $ledger,
        private readonly \Closure $sinks,
    ) {}

    /** React to a breaker state change: Opened/Reopened alert, Closed re-arms, FailedOver alerts about the credential. */
    public function guardTransition(GuardTransition $transition): ?AlertOutcome
    {
        $binding = $transition->binding;
        $subject = self::subject($binding).'/'.$transition->guard->value;

        switch ($transition->transition) {
            case BreakerTransition::Opened:
            case BreakerTransition::Reopened:
                $remedy = $transition->guard === GuardKind::Auth
                    ? 'The provider is refusing the credentials of this connection. Check that the key or certificate was not revoked or rotated, deploy the new one, and the circuit closes after a successful probe.'
                    : 'Too many destinations of this connection are being refused. Check the environment (sandbox vs production), the topic/bundle id and recent app releases.';

                return $this->raise(AlertKind::CircuitOpened, $subject, sprintf('Circuit opened: %s (%s)', $binding->name, $transition->guard->value), $remedy, self::bindingContext($binding) + ['guard' => $transition->guard->value]);
            case BreakerTransition::Closed:
                $this->rearm(AlertKind::CircuitOpened, $subject);

                return null;
            case BreakerTransition::FailedOver:
                return $this->raise(AlertKind::CredentialFailedOver, self::subject($binding), sprintf('Credential failed over: %s', $binding->name), 'The primary credential was refused and sends moved to the secondary credential. Fix or replace the primary and promote the right one.', self::bindingContext($binding));
            default:
                return null;
        }
    }

    /**
     * Alerts for expiring/expired credentials found in a CredentialCheck result.
     *
     * @return list<AlertOutcome>
     */
    public function credentialFindings(BindingRef $binding, CheckResult $result): array
    {
        $outcomes = [];
        foreach ($result->findings as $finding) {
            if ($finding->severity === Severity::Ok) {
                continue;
            }
            $kind = match (true) {
                str_ends_with($finding->code, '.expired') => AlertKind::CredentialExpired,
                str_ends_with($finding->code, '.expiring') => AlertKind::CredentialExpiring,
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $outcomes[] = $this->raise(
                $kind,
                self::subject($binding).'/'.$finding->code,
                sprintf('%s: %s', $kind === AlertKind::CredentialExpired ? 'Credential expired' : 'Credential expiring', $binding->name),
                $finding->message,
                self::bindingContext($binding) + ['finding' => $finding->code],
            );
        }

        return $outcomes;
    }

    /**
     * Periodic conditions: error rate per connection (over `alerts.error_rate.window_seconds`, with a minimum sample),
     * ledger backlog, failing log sinks.
     *
     * @return list<AlertOutcome>
     */
    public function evaluate(): array
    {
        $outcomes = [];

        foreach ($this->recorder->errorRates($this->settings->errorRateWindowSeconds) as $sample) {
            if (! ErrorRate::exceeds($sample->sample, $sample->errors, $this->settings->errorRateMinSample, $this->settings->errorRateThreshold)) {
                continue;
            }
            $rate = $sample->rate($this->settings->errorRateMinSample) ?? 0.0;
            $outcomes[] = $this->raise(
                AlertKind::ErrorRateHigh,
                $sample->binding['connection'].'/'.$sample->binding['provider'].'/'.$sample->binding['environment'],
                sprintf('High error rate: %s', $sample->binding['connection']),
                sprintf('%.0f%% of the last %d outcomes failed or are unknown over %d seconds (threshold %.0f%%). Check the provider status page, the credentials and the network.', $rate * 100, $sample->sample, $this->settings->errorRateWindowSeconds, $this->settings->errorRateThreshold * 100),
                $sample->binding + ['sample' => $sample->sample, 'errors' => $sample->errors],
            );
        }

        if ($this->settings->durable) {
            try {
                $store = ($this->ledger)();
                $age = $store === null ? null : intdiv($store->health()->oldestDueAgeMs ?? 0, 1000);
                if ($age !== null && $age >= $this->settings->ledgerBacklogSeconds) {
                    $outcomes[] = $this->raise(
                        AlertKind::LedgerBacklog,
                        'ledger',
                        'Delivery backlog building',
                        sprintf('The oldest due delivery is %d seconds old (threshold %d). The relay is stuck or too slow: check the queue workers and provider throttling.', $age, $this->settings->ledgerBacklogSeconds),
                        ['oldest_due_age_seconds' => $age, 'threshold_seconds' => $this->settings->ledgerBacklogSeconds],
                    );
                }
            } catch (\Throwable) {
                // An unreachable ledger is reported by the health check; the alerter must not throw.
            }
        }

        try {
            $manager = ($this->sinks)();
            foreach ($manager?->stats()['sinks'] ?? [] as $name => $sink) {
                $failed = $sink['failed'];
                if ($failed > 0 && $failed > ($this->sinkFailedSeen[$name] ?? 0)) {
                    $outcomes[] = $this->raise(
                        AlertKind::LogSinkFailing,
                        $name,
                        sprintf('Log sink failing: %s', $name),
                        sprintf('The "%s" log sink failed to write %d record(s). Sending is not affected. Check the log channel, database or OpenTelemetry endpoint.', $name, $failed),
                        ['sink' => $name, 'failed' => $failed, 'dropped' => $sink['dropped']],
                    );
                }
                $this->sinkFailedSeen[$name] = $failed;
            }
        } catch (\Throwable) {
        }

        return $outcomes;
    }

    /**
     * Raise one alert: cooldown, redaction, simulate-mode handling, delivery. Never throws.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function raise(AlertKind $kind, string $subject, string $title, string $body, array $context = []): AlertOutcome
    {
        if (! $this->settings->alertsEnabled) {
            return AlertOutcome::Disabled;
        }

        $key = $kind->value.':'.$subject;
        if (! $this->claim($key)) {
            return AlertOutcome::Suppressed;
        }

        try {
            $title = $this->redactor->string($title);
            $body = $this->redactor->string($body);
            $safeContext = $this->redactor->value($context);
            $safeContext = is_array($safeContext) ? $safeContext : [];
            $safeKey = $this->redactor->string($key);

            $channels = [];
            $routes = [];
            if ($this->settings->alertMail !== []) {
                $channels[] = 'mail';
                $routes['mail'] = $this->settings->alertMail;
            }
            if ($this->settings->alertSlackWebhook !== null) {
                $channels[] = SlackWebhookChannel::class;
                $routes[SlackWebhookChannel::class] = $this->settings->alertSlackWebhook;
            }

            if ($this->isSimulate() && ! $this->settings->alertSendInSimulate) {
                $this->log('info', 'mobile-push alert (simulate mode, not sent)', $kind, $safeKey, $title, $body, $safeContext);

                return AlertOutcome::Logged;
            }
            if ($channels === []) {
                $this->log('warning', 'mobile-push alert (no alert route configured)', $kind, $safeKey, $title, $body, $safeContext);

                return AlertOutcome::Logged;
            }

            $notifiable = null;
            foreach ($routes as $channel => $route) {
                $notifiable = ($notifiable ?? Notification::route($channel, $route));
                $notifiable->route($channel, $route);
            }
            $notifiable?->notifyNow(new OpsAlert($kind, $safeKey, $title, $body, $safeContext, $channels));

            return AlertOutcome::Sent;
        } catch (\Throwable $e) {
            $this->release($key);
            try {
                $this->logger?->error('mobile-push alert could not be delivered.', ['kind' => $kind->value, 'cause' => $e::class]);
            } catch (\Throwable) {
            }

            return AlertOutcome::Logged;
        }
    }

    /** Forget the cooldown of an alert key (a recovered condition can alert again). */
    public function rearm(AlertKind $kind, string $subject): void
    {
        $this->release($kind->value.':'.$subject);
    }

    private function claim(string $key): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $ttl = $this->settings->alertCooldownSeconds;

        try {
            $cooldownKey = $this->cooldownKey($key);
            if ($this->cache->add($cooldownKey, $now + $ttl, $ttl)) {
                return true;
            }
            // The stored value is the instant the cooldown ends, so the injected clock decides, not only the cache TTL.
            $until = $this->cache->get($cooldownKey);
            if (is_numeric($until) && (int) $until <= $now) {
                $this->cache->put($cooldownKey, $now + $ttl, $ttl);

                return true;
            }

            return false;
        } catch (\Throwable) {
            if (($this->localCooldown[$key] ?? 0) > $now) {
                return false;
            }
            $this->localCooldown[$key] = $now + $ttl;

            return true;
        }
    }

    private function release(string $key): void
    {
        unset($this->localCooldown[$key]);
        try {
            $this->cache->forget($this->cooldownKey($key));
        } catch (\Throwable) {
        }
    }

    private function cooldownKey(string $key): string
    {
        return $this->settings->cachePrefix.':alerts:cooldown:'.sha1($key);
    }

    private function isSimulate(): bool
    {
        try {
            return (new ModeResolver)->resolve($this->settings->mode, $this->settings->appEnvironment) === ExecutionMode::Simulate;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param  array<array-key, mixed>  $context */
    private function log(string $level, string $message, AlertKind $kind, string $key, string $title, string $body, array $context): void
    {
        try {
            $this->logger?->log($level, $message, ['kind' => $kind->value, 'key' => $key, 'title' => $title, 'body' => $body, 'context' => $context]);
        } catch (\Throwable) {
        }
    }

    private static function subject(BindingRef $binding): string
    {
        return $binding->name.'/'.$binding->provider->value.'/'.$binding->environment->value;
    }

    /** @return array{connection: string, provider: string, environment: string} */
    private static function bindingContext(BindingRef $binding): array
    {
        return ['connection' => $binding->name, 'provider' => $binding->provider->value, 'environment' => $binding->environment->value];
    }
}
