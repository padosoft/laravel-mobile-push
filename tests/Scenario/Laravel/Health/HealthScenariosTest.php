<?php

declare(strict_types=1);

require_once __DIR__.'/HealthKit.php';

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Ledger\LedgerHealth;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Health\AlertKind;
use Padosoft\MobilePush\Laravel\Health\AlertOutcome;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\CredentialMonitor;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\HealthStatus;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\OpsAlert;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Laravel\Logging\LogRecord;
use Padosoft\MobilePush\Laravel\Logging\LogSink;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\Canary;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\DownStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\FakeLedger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\HealthKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\World;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

function collectingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<mixed>}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

beforeEach(function (): void {
    Notification::fake();
});

it('reports everything ok when the installation is healthy', function (): void {
    $clock = World::build($this->app);
    $this->app->instance(ConnectionCredentialsSource::class, HealthKit::certificateSource($clock->now()->modify('+200 days'), $clock->now()));
    $this->app->forgetInstance(HealthInspector::class);

    $report = $this->app->make(HealthInspector::class)->inspect();
    $json = $report->toArray();

    $statuses = array_map(static fn (array $c): string => $c['status'], [...$json['liveness']['checks'], ...$json['readiness']['checks']]);
    expect(array_unique($statuses))->toBe(['ok'], json_encode($json))
        ->and($report->status())->toBe(HealthStatus::Ok)
        ->and($report->exitCode())->toBe(0)
        ->and($json['liveness']['status'])->toBe('ok')
        ->and($json['readiness']['status'])->toBe('ok')
        ->and($json['live_probe'])->toContain('no request was sent');
    expect(array_map(static fn (array $c): string => $c['name'], $json['readiness']['checks']))
        ->toContain('credentials.main', 'cache', 'log_sinks', 'guards', 'queue', 'execution_mode');
});

it('says "not verifiable" instead of ok when it cannot see the credentials', function (): void {
    World::build($this->app, ['mobile-push.queue.timeout' => null]);

    $report = $this->app->make(HealthInspector::class)->inspect();

    expect($report->check('credentials')?->status)->toBe(HealthStatus::NotVerifiable)
        ->and($report->check('queue')?->status)->toBe(HealthStatus::NotVerifiable)
        ->and($report->exitCode())->toBe(0);
});

it('warns a week before the certificate expires, fails after it, and alerts once per cooldown', function (): void {
    $clock = World::build($this->app);
    $expires = $clock->now()->modify('+7 days');
    $this->app->instance(ConnectionCredentialsSource::class, HealthKit::certificateSource($expires, $clock->now()));
    foreach ([HealthInspector::class, CredentialMonitor::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }

    $warning = $this->app->make(HealthInspector::class)->inspect();
    $check = $warning->check('credentials.main');
    expect($check?->status)->toBe(HealthStatus::Warning)
        ->and($check?->message)->toContain('expires in 7 day(s)')
        ->and($check?->data['days_remaining'])->toBe(7)
        ->and($warning->exitCode())->toBe(1);

    $monitor = $this->app->make(CredentialMonitor::class);
    $first = $monitor->check();
    expect($first[0]->daysRemaining)->toBe(7)
        ->and($first[0]->alerts)->toBe([AlertOutcome::Sent]);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);

    $clock->advance(600);
    expect($monitor->check()[0]->alerts)->toBe([AlertOutcome::Suppressed]);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);

    // One day after expiry: failed, and a different alert kind (expired) goes out.
    $clock->set($expires->modify('+1 day'));
    $failed = $this->app->make(HealthInspector::class)->inspect();
    expect($failed->check('credentials.main')?->status)->toBe(HealthStatus::Failed)
        ->and($failed->exitCode())->toBe(2)
        ->and($monitor->check()[0]->alerts)->toBe([AlertOutcome::Sent]);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 2);

    // After the cooldown the same condition may alert again.
    $clock->advance(3601);
    expect($monitor->check()[0]->alerts)->toBe([AlertOutcome::Sent]);
});

it('reports a dead cache as failed, never throws, and turns metrics into a silent no-op', function (): void {
    Cache::extend('down', static fn (): Repository => new Repository(new DownStore));
    World::build($this->app, ['cache.stores.down' => ['driver' => 'down'], 'mobile-push.cache.store' => 'down']);

    $report = $this->app->make(HealthInspector::class)->inspect();
    $cache = $report->check('cache');
    expect($cache?->status)->toBe(HealthStatus::Failed)
        ->and($cache?->message)->toContain('not reachable')
        ->and($cache?->message)->toContain('MOBILE_PUSH_CACHE_STORE')
        ->and($cache?->message)->not->toContain('s3cr3t')
        ->and($report->exitCode())->toBe(2);

    $recorder = $this->app->make(MetricsRecorder::class);
    $recorder->record(HealthKit::binding(), HealthKit::result(SendStatus::Accepted), TrafficClass::Transactional);
    $recorder->recordGuardTransition(new GuardTransition(HealthKit::binding(), GuardKind::Auth, BreakerTransition::Opened, 0));
    expect($recorder->snapshot())->toBe([]);

    // The alerter falls back to an in-process cooldown instead of paging on every call.
    $alerter = $this->app->make(OpsAlerter::class);
    $transition = new GuardTransition(HealthKit::binding(), GuardKind::Auth, BreakerTransition::Opened, 0);
    expect($alerter->guardTransition($transition))->toBe(AlertOutcome::Sent)
        ->and($alerter->guardTransition($transition))->toBe(AlertOutcome::Suppressed);
});

it('shows a building backlog as a warning, then failed, and alerts once', function (): void {
    $clock = World::build($this->app, ['mobile-push.delivery.profile' => 'durable', 'mobile-push.health.ledger_backlog_seconds' => 300, 'mobile-push.health.ledger_backlog_failed_seconds' => 1800]);
    $ledger = new FakeLedger(new LedgerHealth(due: 42, pending: 40, deferred: 2, inFlight: 0, unknown: 1, oldestDueAgeMs: 90_000));
    $this->app->instance(DeliveryStore::class, $ledger);

    $inspector = $this->app->make(HealthInspector::class);
    expect($inspector->inspect()->check('ledger')?->status)->toBe(HealthStatus::Ok);
    expect($this->app->make(OpsAlerter::class)->evaluate())->toBe([]);

    $ledger->health = new LedgerHealth(42, 40, 2, 0, 1, 600_000);
    $warning = $inspector->inspect();
    expect($warning->check('ledger')?->status)->toBe(HealthStatus::Warning)
        ->and($warning->check('ledger')?->data['oldest_due_age_seconds'])->toBe(600)
        ->and($warning->check('ledger')?->message)->toContain('relay');

    $alerter = $this->app->make(OpsAlerter::class);
    expect($alerter->evaluate())->toBe([AlertOutcome::Sent]);
    Notification::assertSentOnDemand(OpsAlert::class, static fn (OpsAlert $a): bool => $a->kind === AlertKind::LedgerBacklog);
    $clock->advance(60);
    expect($alerter->evaluate())->toBe([AlertOutcome::Suppressed]);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);

    $ledger->health = new LedgerHealth(900, 900, 0, 0, 0, 7_200_000);
    expect($inspector->inspect()->check('ledger')?->status)->toBe(HealthStatus::Failed);
});

it('does not touch the ledger check when the light profile is on', function (): void {
    World::build($this->app);

    expect($this->app->make(HealthInspector::class)->inspect()->check('ledger'))->toBeNull();
});

it('alerts once when the key is revoked, stays quiet while it repeats, and re-arms when the circuit closes', function (): void {
    $clock = World::build($this->app);
    $alerter = $this->app->make(OpsAlerter::class);
    $binding = HealthKit::binding();
    $opened = new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Opened, 0);

    expect($alerter->guardTransition($opened))->toBe(AlertOutcome::Sent);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);

    $clock->advance(120);
    expect($alerter->guardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Reopened, 0)))->toBe(AlertOutcome::Suppressed)
        ->and($alerter->guardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::HalfOpened, 0)))->toBeNull()
        ->and($alerter->guardTransition($opened))->toBe(AlertOutcome::Suppressed);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);

    // Another circuit (destination quality) is another alert key.
    expect($alerter->guardTransition(new GuardTransition($binding, GuardKind::DestinationQuality, BreakerTransition::Opened, 0)))->toBe(AlertOutcome::Sent);

    expect($alerter->guardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Closed, 0)))->toBeNull()
        ->and($alerter->guardTransition($opened))->toBe(AlertOutcome::Sent);
    Notification::assertSentOnDemandTimes(OpsAlert::class, 3);
    Notification::assertSentOnDemand(OpsAlert::class, static fn (OpsAlert $a, array $channels, object $notifiable): bool => $a->kind === AlertKind::CircuitOpened
        && $channels === ['mail']
        && $notifiable->routes['mail'] === ['ops@example.test']);
});

it('raises credential_failed_over when the breaker switches to the secondary', function (): void {
    World::build($this->app);

    $outcome = $this->app->make(OpsAlerter::class)->guardTransition(new GuardTransition(HealthKit::binding(), GuardKind::Auth, BreakerTransition::FailedOver, 1));

    expect($outcome)->toBe(AlertOutcome::Sent);
    Notification::assertSentOnDemand(OpsAlert::class, static fn (OpsAlert $a): bool => $a->kind === AlertKind::CredentialFailedOver);
});

it('shows a prominent warning and only logs alerts when simulate runs in production', function (): void {
    World::build($this->app, ['mobile-push.mode' => 'simulate'], 'production');
    $logger = collectingLogger();
    $this->app->instance(LoggerInterface::class, $logger);
    $this->app->forgetInstance(OpsAlerter::class);

    $report = $this->app->make(HealthInspector::class)->inspect();
    $mode = $report->check('execution_mode');
    expect($mode?->status)->toBe(HealthStatus::Warning)
        ->and($mode?->message)->toContain('SIMULATE MODE IN PRODUCTION')
        ->and($report->exitCode())->toBe(1);

    $outcome = $this->app->make(OpsAlerter::class)->guardTransition(new GuardTransition(HealthKit::binding(), GuardKind::Auth, BreakerTransition::Opened, 0));
    expect($outcome)->toBe(AlertOutcome::Logged);
    Notification::assertNothingSent();
    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('simulate mode, not sent');

    // Explicitly allowed: it goes out.
    World::build($this->app, ['mobile-push.mode' => 'simulate', 'mobile-push.alerts.send_in_simulate' => true], 'production');
    $this->app->instance(LoggerInterface::class, $logger);
    expect($this->app->make(OpsAlerter::class)->guardTransition(new GuardTransition(HealthKit::binding('other'), GuardKind::Auth, BreakerTransition::Opened, 0)))->toBe(AlertOutcome::Sent);
});

it('warns when live mode runs outside production', function (): void {
    World::build($this->app, ['mobile-push.mode' => 'live'], 'local');

    $mode = $this->app->make(HealthInspector::class)->inspect()->check('execution_mode');

    expect($mode?->status)->toBe(HealthStatus::Warning)->and($mode?->message)->toContain('LIVE MODE');
});

it('flags an invalid mode and an incoherent queue as failed with a remedy', function (): void {
    World::build($this->app, ['mobile-push.mode' => 'fast', 'queue.connections.redis.retry_after' => 60, 'mobile-push.queue.timeout' => 60]);

    $report = $this->app->make(HealthInspector::class)->inspect();

    expect($report->check('config')?->status)->toBe(HealthStatus::Failed)
        ->and($report->check('execution_mode')?->status)->toBe(HealthStatus::Failed)
        ->and($report->check('queue')?->status)->toBe(HealthStatus::Failed)
        ->and($report->check('queue')?->message)->toContain('retry_after');
});

it('alerts on a high error rate only with enough samples', function (): void {
    $clock = World::build($this->app, ['mobile-push.alerts.error_rate.min_sample' => 20, 'mobile-push.alerts.error_rate.threshold' => 0.5, 'mobile-push.alerts.error_rate.window_seconds' => 300]);
    $recorder = $this->app->make(MetricsRecorder::class);
    $alerter = $this->app->make(OpsAlerter::class);
    $binding = HealthKit::binding();

    for ($i = 0; $i < 10; $i++) {
        $recorder->record($binding, HealthKit::result(SendStatus::Failed, ReasonCode::ProviderUnavailable), TrafficClass::Bulk);
    }
    expect($alerter->evaluate())->toBe([]); // 100% errors, but only 10 samples

    for ($i = 0; $i < 12; $i++) {
        $recorder->record($binding, HealthKit::result(SendStatus::Rejected, ReasonCode::DestinationUnregistered), TrafficClass::Bulk); // churn: sampled, not an error
    }
    expect($alerter->evaluate())->toBe([]); // 22 samples, 10 errors = 45%

    for ($i = 0; $i < 6; $i++) {
        $recorder->record($binding, HealthKit::result(SendStatus::OutcomeUnknown, ReasonCode::TransportUnknown), TrafficClass::Bulk);
    }
    expect($alerter->evaluate())->toBe([AlertOutcome::Sent]); // 28 samples, 16 errors = 57%
    Notification::assertSentOnDemand(OpsAlert::class, static fn (OpsAlert $a): bool => $a->kind === AlertKind::ErrorRateHigh);

    // Old samples fall out of the window.
    $clock->advance(900);
    expect($alerter->evaluate())->toBe([]);
});

it('alerts when a log sink starts failing', function (): void {
    World::build($this->app);
    $manager = new SinkManager([
        'database' => new class implements LogSink
        {
            public function write(LogRecord ...$records): void
            {
                throw new RuntimeException('db down');
            }

            public function flush(): void {}
        },
    ], 1);
    $this->app->instance(SinkManager::class, $manager);
    $record = (new ReflectionClass(LogRecord::class))->newInstanceWithoutConstructor();
    $manager->record($record);
    $manager->flush();

    $alerts = $this->app->make(OpsAlerter::class)->evaluate();
    $warning = $this->app->make(HealthInspector::class)->inspect()->check('log_sinks');

    expect($alerts)->toBe([AlertOutcome::Sent])
        ->and($warning?->status)->toBe(HealthStatus::Warning)
        ->and($warning?->message)->toContain('database');
    expect($this->app->make(OpsAlerter::class)->evaluate())->toBe([]); // no new failure
});

it('never lets a token or bearer reach the health JSON, the metrics text or an alert', function (): void {
    World::build($this->app, ['mobile-push.metrics.prometheus.enabled' => true]);
    $logger = collectingLogger();
    $recorder = $this->app->make(MetricsRecorder::class);
    $binding = HealthKit::binding();

    foreach ([SendStatus::Accepted, SendStatus::Rejected, SendStatus::Failed] as $status) {
        $result = HealthKit::result($status, ReasonCode::AuthTokenInvalid, Canary::TOKEN, 'Bearer '.Canary::BEARER.' token='.Canary::TOKEN);
        $recorder->record($binding, $result, TrafficClass::Transactional);
    }

    $redactor = new Redactor(Canary::all());
    $this->app->instance(Redactor::class, $redactor);
    $this->app->forgetInstance(OpsAlerter::class);
    $alerter = $this->app->make(OpsAlerter::class);
    $alerter->raise(AlertKind::ErrorRateHigh, 'canary', 'Failing with Bearer '.Canary::BEARER, 'The device '.Canary::TOKEN.' answered Authorization: Bearer '.Canary::BEARER, [
        'device_token' => Canary::TOKEN, 'note' => 'seen '.Canary::TOKEN, 'nested' => ['raw' => Canary::BEARER, 'authorization' => 'Bearer '.Canary::BEARER],
    ]);

    $health = json_encode($this->app->make(HealthInspector::class)->inspect()->toArray(), JSON_THROW_ON_ERROR);
    $metrics = $this->app->make(PrometheusExporter::class)->render();
    $sent = [];
    Notification::assertSentOnDemand(OpsAlert::class, static function (OpsAlert $a) use (&$sent): bool {
        $sent[] = json_encode([$a->title, $a->body, $a->context, $a->toSlackWebhook(), $a->toMail(new stdClass)->render()->toHtml()], JSON_THROW_ON_ERROR);

        return true;
    });

    expect($sent)->not->toBe([]);
    foreach ([$health, $metrics, ...$sent] as $output) {
        foreach (Canary::all() as $canary) {
            expect($output)->not->toContain($canary);
        }
    }
});
