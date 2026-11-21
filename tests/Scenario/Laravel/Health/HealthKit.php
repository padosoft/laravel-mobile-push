<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Health;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Credentials\ApnsCertificateInspector;
use Padosoft\MobilePush\Core\Credentials\CertificateInfo;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Ledger\Delivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Ledger\Lease;
use Padosoft\MobilePush\Core\Ledger\LedgerHealth;
use Padosoft\MobilePush\Core\Ledger\ReclaimReport;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentials;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\NullConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\CredentialMonitor;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\HealthSettings;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Padosoft\MobilePush\Tests\Unit\Core\Credentials\CertificateFactory;
use Psr\Clock\ClockInterface;

/** Recognisable fakes: if any of these strings shows up in an output, something leaked. */
final class Canary
{
    public const TOKEN = 'CANARY-DEVICE-TOKEN-0123456789abcdef0123456789abcdef';

    public const BEARER = 'ya29.CANARY-BEARER-abcdefghijklmnopqrstuvwxyz0123456789';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::TOKEN, self::BEARER];
    }
}

final class HealthKit
{
    public static function binding(string $name = 'main', Provider $provider = Provider::Apns, Environment $env = Environment::Production): BindingRef
    {
        return new BindingRef('default', $name, $provider, 'com.example.app', $env);
    }

    public static function result(SendStatus $status, ReasonCode $code = ReasonCode::None, string $token = 'tok-1', ?string $raw = null): SendResult
    {
        return new SendResult(FcmToken::of($token.str_repeat('Zq9', 20)), $status, Reason::of($code, $raw));
    }

    /** A source that returns one connection per certificate, inspected the way the real source will do it. */
    public static function certificateSource(\DateTimeImmutable $expires, \DateTimeImmutable $now, string $name = 'main'): ConnectionCredentialsSource
    {
        static $base = null;
        if ($base === null) {
            $made = CertificateFactory::make(universal: true, days: 365);
            $base = (new ApnsCertificateInspector)->fromPem($made['cert'], $made['key']);
        }
        $info = $base;
        // The factory signs "N days from the real now": move the validity window to the wanted instants.
        $info = new CertificateInfo(
            $info->commonName, $info->teamId, $now->modify('-1 day'), $expires, $info->fingerprintSha256, $info->topics, $info->kind, $info->keyMatches,
        );

        return new FixedSource([new ConnectionCredentials(self::binding($name), certificate: $info)]);
    }
}

final class World
{
    /**
     * Configure the application BEFORE the health singletons are built (the provider resolves some of them at boot with
     * default config), and give it a fake clock. Returns the clock.
     *
     * @param  array<string, mixed>  $config  dotted keys, written as-is
     */
    public static function build(Application $app, array $config = [], string $env = 'production'): FakeClock
    {
        $clock = new FakeClock('2026-11-20T10:00:00Z');
        $app->instance(ClockInterface::class, $clock);
        $app['env'] = $env;
        // These scenarios are about the health module on its own: start without a credentials source (the
        // integration module binds the manager's one by default); a scenario that needs one binds it after build().
        $app->instance(ConnectionCredentialsSource::class, new NullConnectionCredentialsSource);

        $defaults = [
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.retry_after' => 120,
            'mobile-push.queue.timeout' => 60,
            'mobile-push.alerts.enabled' => true,
            'mobile-push.alerts.mail' => ['ops@example.test'],
            'mobile-push.alerts.cooldown_seconds' => 3600,
            'mobile-push.cache.store' => 'array',
        ];
        foreach ([...$defaults, ...$config] as $key => $value) {
            $app['config']->set($key, $value);
        }
        foreach ([HealthSettings::class, MetricsRecorder::class, PrometheusExporter::class, HealthInspector::class, OpsAlerter::class, CredentialMonitor::class] as $abstract) {
            $app->forgetInstance($abstract);
        }

        return $clock;
    }
}

final class FixedSource implements ConnectionCredentialsSource
{
    /** @param  list<ConnectionCredentials>  $connections */
    public function __construct(private array $connections) {}

    public function available(): bool
    {
        return true;
    }

    public function connections(): array
    {
        return $this->connections;
    }
}

/** A cache store that is down: every call throws, like a Redis server that went away. */
final class DownStore implements Store
{
    private function down(): never
    {
        throw new \RuntimeException('Connection refused redis://:s3cr3t-password@10.0.0.1:6379');
    }

    public function get($key)
    {
        $this->down();
    }

    public function many(array $keys)
    {
        $this->down();
    }

    public function put($key, $value, $seconds)
    {
        $this->down();
    }

    public function putMany(array $values, $seconds)
    {
        $this->down();
    }

    public function increment($key, $value = 1)
    {
        $this->down();
    }

    public function decrement($key, $value = 1)
    {
        $this->down();
    }

    public function forever($key, $value)
    {
        $this->down();
    }

    public function forget($key)
    {
        $this->down();
    }

    public function flush()
    {
        $this->down();
    }

    public function touch($key, $seconds)
    {
        $this->down();
    }

    public function getPrefix()
    {
        return '';
    }
}

/** A ledger that only knows how to report its health. */
final class FakeLedger implements DeliveryStore
{
    public function __construct(public LedgerHealth $health) {}

    public function health(?string $scope = null): LedgerHealth
    {
        return $this->health;
    }

    public function claimDue(string $scope, string $owner, int $limit, ?string $effectId = null, ?int $leaseSeconds = null): array
    {
        return [];
    }

    public function markAttemptStarted(Lease $lease): bool
    {
        return false;
    }

    public function record(Lease $lease, SendResult $result, ?RetryBudget $budget = null): Recording
    {
        throw new \LogicException('not used');
    }

    public function reclaimExpiredLeases(string $scope, int $limit = 500): ReclaimReport
    {
        return new ReclaimReport;
    }

    public function expireDue(string $scope, int $limit = 500): int
    {
        return 0;
    }

    public function delivery(string $scope, string $deliveryId): ?Delivery
    {
        return null;
    }

    public function counts(string $scope, string $effectId): DeliveryCounts
    {
        throw new \LogicException('not used');
    }

    public function campaignCounts(string $scope, string $campaignId): DeliveryCounts
    {
        throw new \LogicException('not used');
    }

    public function results(string $scope, string $effectId): \Generator
    {
        yield from [];
    }

    public function scopesWithWork(int $limit = 100): array
    {
        return [];
    }
}

/** A deliberately naive Prometheus text parser: enough to prove the output is well formed. */
final class PromText
{
    /**
     * @return array{types: array<string, string>, help: array<string, string>, samples: list<array{name: string, labels: array<string, string>, value: float}>}
     */
    public static function parse(string $text): array
    {
        $types = $help = $samples = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '# HELP ')) {
                [$name, $rest] = explode(' ', substr($line, 7), 2) + [1 => ''];
                $help[$name] = $rest;

                continue;
            }
            if (str_starts_with($line, '# TYPE ')) {
                [$name, $type] = explode(' ', substr($line, 7), 2);
                $types[$name] = $type;

                continue;
            }
            if (! preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{(.*)\})? (\S+)$/', $line, $m)) {
                throw new \RuntimeException('Unparseable line: '.$line);
            }
            $labels = [];
            if (($m[2] ?? '') !== '') {
                if (! preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $m[2], $all, PREG_SET_ORDER)) {
                    throw new \RuntimeException('Unparseable labels: '.$line);
                }
                foreach ($all as $pair) {
                    $labels[$pair[1]] = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $pair[2]);
                }
            }
            $samples[] = ['name' => $m[1], 'labels' => $labels, 'value' => (float) $m[3]];
        }

        return ['types' => $types, 'help' => $help, 'samples' => $samples];
    }
}
