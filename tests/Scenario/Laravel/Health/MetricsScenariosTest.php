<?php

declare(strict_types=1);

require_once __DIR__.'/HealthKit.php';

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Ledger\LedgerHealth;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Health\HealthModule;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\FakeLedger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\HealthKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\PromText;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\World;
use Psr\Clock\ClockInterface;

const ALLOWED_LABELS = ['provider', 'connection', 'environment', 'status', 'reason', 'class', 'guard', 'transition'];

it('exposes what was recorded, with bounded labels, even after 1,000 distinct destinations', function (): void {
    World::build($this->app, ['mobile-push.delivery.profile' => 'durable']);
    $this->app->instance(DeliveryStore::class, new FakeLedger(new LedgerHealth(7, 5, 2, 1, 3, 12_500)));
    $recorder = $this->app->make(MetricsRecorder::class);
    $apns = HealthKit::binding('ios');
    $fcm = HealthKit::binding('android', Provider::Fcm);

    $expected = [];
    for ($i = 0; $i < 300; $i++) {
        $binding = $i % 3 === 0 ? $fcm : $apns;
        $status = [SendStatus::Accepted, SendStatus::Accepted, SendStatus::Rejected, SendStatus::OutcomeUnknown][$i % 4];
        $code = $status === SendStatus::Rejected ? ReasonCode::DestinationUnregistered : ReasonCode::None;
        $class = $i % 5 === 0 ? TrafficClass::Transactional : TrafficClass::Bulk;
        $result = new SendResult(FcmToken::of('device-'.$i.str_repeat('Zq9', 20)), $status, Reason::of($code));
        $recorder->record($binding, $result, $class);
        $key = implode('|', [$binding->provider->value, $binding->name, $status->value, $code->value, $class->value]);
        $expected[$key] = ($expected[$key] ?? 0) + 1;
    }
    $recorder->recordGuardTransition(new GuardTransition($apns, GuardKind::Auth, BreakerTransition::Opened, 0));
    $recorder->recordThrottle($fcm, TrafficClass::Bulk, $this->app->make(ClockInterface::class)->now()->modify('+30 seconds'));

    $text = $this->app->make(PrometheusExporter::class)->render();
    $parsed = PromText::parse($text);

    expect($parsed['types']['mobile_push_results_total'])->toBe('counter')
        ->and($parsed['types']['mobile_push_ledger_due'])->toBe('gauge');
    $total = 0;
    foreach ($parsed['samples'] as $sample) {
        expect(array_diff(array_keys($sample['labels']), ALLOWED_LABELS))->toBe([]);
        expect($sample['name'])->toStartWith('mobile_push_');
        if ($sample['name'] === 'mobile_push_results_total') {
            $l = $sample['labels'];
            $key = implode('|', [$l['provider'], $l['connection'], $l['status'], $l['reason'], $l['class']]);
            expect($sample['value'])->toBe((float) $expected[$key]);
            $total += (int) $sample['value'];
        }
    }
    expect($total)->toBe(300);

    $one = static fn (string $name, array $labels = []): ?float => array_values(array_map(
        static fn (array $s): float => $s['value'],
        array_filter($parsed['samples'], static fn (array $s): bool => $s['name'] === $name && array_intersect_assoc($labels, $s['labels']) === $labels),
    ))[0] ?? null;
    expect($one('mobile_push_ledger_due'))->toBe(7.0)
        ->and($one('mobile_push_ledger_unknown'))->toBe(3.0)
        ->and($one('mobile_push_ledger_oldest_due_age_seconds'))->toBe(12.5)
        ->and($one('mobile_push_circuit_open', ['connection' => 'ios', 'guard' => 'auth']))->toBe(1.0)
        ->and($one('mobile_push_account_throttled', ['connection' => 'android']))->toBe(1.0);

    // Series stay bounded: the destination is never a label, so 700 more distinct destinations add nothing.
    $before = count($parsed['samples']);
    for ($i = 300; $i < 1000; $i++) {
        $recorder->record($apns, new SendResult(FcmToken::of('device-'.$i.str_repeat('Zq9', 20)), SendStatus::Accepted, Reason::none()), TrafficClass::Bulk);
    }
    expect(count(PromText::parse($this->app->make(PrometheusExporter::class)->render())['samples']))->toBe($before);
    expect($text)->not->toContain('device-');
});

it('stops creating series beyond the configured cap', function (): void {
    World::build($this->app, ['mobile-push.metrics.max_series' => 10]);
    $recorder = $this->app->make(MetricsRecorder::class);

    foreach (range(1, 40) as $i) {
        $recorder->record(HealthKit::binding('conn-'.$i), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);
    }

    expect(count($recorder->snapshot()))->toBeLessThanOrEqual(10);
});

it('records nothing when metrics are disabled', function (): void {
    World::build($this->app, ['mobile-push.metrics.enabled' => false]);
    $recorder = $this->app->make(MetricsRecorder::class);

    $recorder->record(HealthKit::binding(), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);

    expect($recorder->snapshot())->toBe([]);
});

it('has no metrics route unless it is enabled', function (): void {
    World::build($this->app);
    HealthModule::boot($this->app);

    expect(Route::has('mobile-push.metrics'))->toBeFalse();
    $this->get('/mobile-push/metrics')->assertNotFound();
});

it('refuses every request when the endpoint is enabled without a token', function (): void {
    World::build($this->app, ['mobile-push.metrics.prometheus.enabled' => true]);
    HealthModule::boot($this->app);

    $this->get('/mobile-push/metrics')->assertStatus(403);
    $this->withHeader('Authorization', 'Bearer anything')->get('/mobile-push/metrics')->assertStatus(403);
});

it('answers only the right token, with the Prometheus content type', function (): void {
    World::build($this->app, ['mobile-push.metrics.prometheus.enabled' => true, 'mobile-push.metrics.prometheus.token' => 'scrape-secret-value', 'mobile-push.metrics.prometheus.path' => 'ops/metrics']);
    $this->app->make(MetricsRecorder::class)->record(HealthKit::binding(), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);
    HealthModule::boot($this->app);

    $this->get('/ops/metrics')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer wrong')->get('/ops/metrics')->assertStatus(401);
    $this->get('/ops/metrics?token=scrape-secret-value')->assertStatus(401);

    $ok = $this->withHeader('Authorization', 'Bearer scrape-secret-value')->get('/ops/metrics');
    $ok->assertOk();
    expect($ok->headers->get('Content-Type'))->toStartWith('text/plain; version=0.0.4')
        ->and($ok->getContent())->toContain('mobile_push_results_total{');
});

it('is public only when the middleware is explicitly emptied', function (): void {
    World::build($this->app, ['mobile-push.metrics.prometheus.enabled' => true, 'mobile-push.metrics.prometheus.middleware' => []]);
    HealthModule::boot($this->app);

    $this->get('/mobile-push/metrics')->assertOk();
});
