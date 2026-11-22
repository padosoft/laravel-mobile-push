<?php

declare(strict_types=1);

require_once __DIR__.'/HealthKit.php';
require_once __DIR__.'/MetricsDoubles.php';

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Health\ErrorRateSample;
use Padosoft\MobilePush\Laravel\Health\Metric;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\MetricType;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\Canary;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\DownStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\HealthKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\LocklessStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\MetricsRig as Rig;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\ScriptedLock;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\SpyStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

afterEach(function (): void {
    Carbon::setTestNow();
});

const MRX_RESULTS_LABELS = ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns', 'reason' => 'none', 'status' => 'accepted'];

function mrxBucket(Rig $rig): int
{
    return intdiv($rig->clock->now()->getTimestamp(), 60);
}

/** @return array<string, list<array{name: string, labels: array<string, string>, value: int|float, type: MetricType}>> */
function mrxByName(MetricsRecorder $recorder): array
{
    $by = [];
    foreach ($recorder->snapshot() as $m) {
        $by[$m->name][] = ['name' => $m->name, 'labels' => $m->labels, 'value' => $m->value, 'type' => $m->type];
    }

    return $by;
}

// ---------------------------------------------------------------------------------------------------------------------
// Key layout and what a result writes
// ---------------------------------------------------------------------------------------------------------------------

it('keeps a counter under <prefix>:metrics:c:<sha1>, a window per minute and kind, and lists the series in a JSON index', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    $rig->record($binding, SendStatus::Accepted);
    $rig->record($binding, SendStatus::Accepted);

    $counter = Rig::seriesKey('c', MetricsRecorder::RESULTS, MRX_RESULTS_LABELS);
    $windowSeries = Rig::seriesKey('b', '', Rig::labels('ios'));
    $bucket = mrxBucket($rig);

    expect($rig->repo->get($counter))->toBe(2)
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('ios'), $bucket, 's')))->toBe(2)
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('ios'), $bucket, 'e')))->toBeNull()
        ->and($rig->index())->toBe([
            $counter => ['k' => 'c', 'n' => MetricsRecorder::RESULTS, 'l' => MRX_RESULTS_LABELS],
            $windowSeries => ['k' => 'b', 'n' => '', 'l' => Rig::labels('ios')],
        ]);
    expect(str_starts_with($counter, 'pfx:metrics:c:'))->toBeTrue()
        ->and(strlen($counter))->toBe(strlen('pfx:metrics:c:') + 40);
});

it('puts the window of an error under both kinds and expires it window + 120 seconds later', function (): void {
    $rig = new Rig(window: 300);
    $rig->record(HealthKit::binding('ios'), SendStatus::Failed, ReasonCode::TransportNotSent);
    $bucket = mrxBucket($rig);
    $sample = Rig::windowKey(Rig::labels('ios'), $bucket, 's');
    $error = Rig::windowKey(Rig::labels('ios'), $bucket, 'e');

    expect($rig->repo->get($sample))->toBe(1)->and($rig->repo->get($error))->toBe(1);

    $rig->advance(419);
    expect($rig->repo->get($sample))->toBe(1)->and($rig->repo->get($error))->toBe(1);

    $rig->advance(1);
    expect($rig->repo->get($sample))->toBeNull()->and($rig->repo->get($error))->toBeNull();
});

it('counts every status in the results counter but samples only the ones that carry a provider verdict', function (SendStatus $status, ReasonCode $code, bool $sampled, bool $error): void {
    $rig = new Rig;
    $rig->record(HealthKit::binding('ios'), $status, $code);
    $bucket = mrxBucket($rig);
    $labels = ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns', 'reason' => $code->value, 'status' => $status->value];

    expect($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels)))->toBe(1)
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('ios'), $bucket, 's')))->toBe($sampled ? 1 : null)
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('ios'), $bucket, 'e')))->toBe($error ? 1 : null);
    // A status that is not sampled does not even get a window series in the index.
    expect(array_key_exists(Rig::seriesKey('b', '', Rig::labels('ios')), $rig->index()))->toBe($sampled);
})->with([
    'accepted' => [SendStatus::Accepted, ReasonCode::None, true, false],
    'rejected: unregistered token' => [SendStatus::Rejected, ReasonCode::DestinationUnregistered, true, false],
    'rejected: payload invalid' => [SendStatus::Rejected, ReasonCode::PayloadInvalid, true, true],
    'failed' => [SendStatus::Failed, ReasonCode::ProviderUnavailable, true, true],
    'outcome unknown' => [SendStatus::OutcomeUnknown, ReasonCode::TransportUnknown, true, true],
    'deferred' => [SendStatus::Deferred, ReasonCode::QuotaExceeded, false, false],
    'expired' => [SendStatus::Expired, ReasonCode::Expired, false, false],
    'skipped' => [SendStatus::Skipped, ReasonCode::PolicyIneligible, false, false],
    'simulated' => [SendStatus::Simulated, ReasonCode::Simulated, false, false],
    'validated' => [SendStatus::Validated, ReasonCode::Validated, false, false],
]);

it('writes nothing, not even a cache call, when metrics are disabled', function (): void {
    $rig = new Rig(enabled: false);
    $binding = HealthKit::binding('ios');

    $rig->record($binding, SendStatus::Accepted);
    $rig->recorder->recordThrottle($binding, TrafficClass::Bulk);
    $rig->recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Opened, 1));

    expect($rig->recorder->enabled())->toBeFalse()
        ->and($rig->recorder->snapshot())->toBe([])
        ->and($rig->recorder->errorRates())->toBe([])
        ->and($rig->recorder->openCircuits())->toBe([])
        ->and($rig->recorder->throttledAccounts())->toBe([])
        ->and($rig->store->log)->toBe([])
        ->and($rig->store->all())->toBe([]);
    expect((new Rig)->recorder->enabled())->toBeTrue();
});

it('shows the data of an enabled recorder as empty through a disabled one over the same cache', function (): void {
    $rig = new Rig;
    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);
    $off = new MetricsRecorder($rig->repo, $rig->clock, false, Rig::PREFIX);

    expect($rig->recorder->snapshot())->toHaveCount(1)
        ->and($rig->recorder->errorRates())->toHaveCount(1)
        ->and($off->snapshot())->toBe([])
        ->and($off->errorRates())->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// Throttle gauge
// ---------------------------------------------------------------------------------------------------------------------

it('keeps the throttle gauge as the instant the pause ends, reports it as 1 until then and drops it 60 seconds after', function (): void {
    $rig = new Rig;
    $ts = $rig->clock->now()->getTimestamp();
    $binding = HealthKit::binding('ios');
    $labels = ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns'];
    $gauge = Rig::seriesKey('s', MetricsRecorder::ACCOUNT_THROTTLED, $labels);

    $rig->recorder->recordThrottle($binding, TrafficClass::Bulk, $rig->clock->now()->modify('+120 seconds'));

    $by = mrxByName($rig->recorder);
    expect($rig->repo->get($gauge))->toBe($ts + 120)
        ->and($by[MetricsRecorder::THROTTLED])->toBe([['name' => MetricsRecorder::THROTTLED, 'labels' => $labels, 'value' => 1, 'type' => MetricType::Counter]])
        ->and($by[MetricsRecorder::ACCOUNT_THROTTLED])->toBe([['name' => MetricsRecorder::ACCOUNT_THROTTLED, 'labels' => $labels, 'value' => 1, 'type' => MetricType::Gauge]])
        ->and($rig->recorder->throttledAccounts())->toBe([$labels])
        ->and($rig->recorder->openCircuits())->toBe([]);

    $rig->advance(119);
    expect($rig->recorder->throttledAccounts())->toBe([$labels]);

    $rig->advance(1);   // the pause ends exactly now: no longer throttled
    expect($rig->recorder->throttledAccounts())->toBe([])
        ->and(mrxByName($rig->recorder)[MetricsRecorder::ACCOUNT_THROTTLED][0]['value'])->toBe(0);

    $rig->advance(59);  // 179 s: the entry lives until + 60 = 180 s
    expect($rig->repo->get($gauge))->toBe($ts + 120);

    $rig->advance(1);   // 180 s
    expect($rig->repo->get($gauge))->toBeNull()
        ->and(mrxByName($rig->recorder))->not->toHaveKey(MetricsRecorder::ACCOUNT_THROTTLED);
});

it('assumes a 60 second pause when the throttle does not say until when', function (): void {
    $rig = new Rig;
    $ts = $rig->clock->now()->getTimestamp();
    $labels = ['class' => 'transactional', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns'];
    $gauge = Rig::seriesKey('s', MetricsRecorder::ACCOUNT_THROTTLED, $labels);

    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Transactional);

    expect($rig->repo->get($gauge))->toBe($ts + 60)
        ->and($rig->recorder->throttledAccounts())->toBe([$labels]);

    $rig->advance(59);
    expect($rig->recorder->throttledAccounts())->toBe([$labels]);

    $rig->advance(1);
    expect($rig->recorder->throttledAccounts())->toBe([]);

    $rig->advance(59);   // 119 s: the entry lives 120 s
    expect($rig->repo->get($gauge))->toBe($ts + 60);

    $rig->advance(1);    // 120 s
    expect($rig->repo->get($gauge))->toBeNull();
});

it('keeps a pause that already ended for at least 60 seconds, never a negative lifetime', function (): void {
    $rig = new Rig;
    $ts = $rig->clock->now()->getTimestamp();
    $labels = ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns'];
    $gauge = Rig::seriesKey('s', MetricsRecorder::ACCOUNT_THROTTLED, $labels);

    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk, $rig->clock->now()->modify('-500 seconds'));

    expect($rig->repo->get($gauge))->toBe($ts - 500)
        ->and($rig->recorder->throttledAccounts())->toBe([]);
    $rig->advance(59);
    expect($rig->repo->get($gauge))->toBe($ts - 500);
    $rig->advance(1);
    expect($rig->repo->get($gauge))->toBeNull();
});

it('counts a throttle once per call, per connection and traffic class', function (): void {
    $rig = new Rig;
    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);
    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);
    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Transactional);
    $rig->recorder->recordThrottle(HealthKit::binding('android', Provider::Fcm), TrafficClass::Bulk);

    $counts = [];
    foreach (mrxByName($rig->recorder)[MetricsRecorder::THROTTLED] as $m) {
        $counts[$m['labels']['provider'].'/'.$m['labels']['connection'].'/'.$m['labels']['class']] = $m['value'];
    }
    ksort($counts);

    expect($counts)->toBe(['apns/ios/bulk' => 2, 'apns/ios/transactional' => 1, 'fcm/android/bulk' => 1])
        ->and($rig->recorder->throttledAccounts())->toHaveCount(3);
});

// ---------------------------------------------------------------------------------------------------------------------
// Circuit gauge
// ---------------------------------------------------------------------------------------------------------------------

it('sets the circuit gauge from the transition: open, reopened and half-open are 1, closed is 0, the others leave it alone', function (BreakerTransition $transition, ?int $gauge): void {
    $rig = new Rig;
    $rig->recorder->recordGuardTransition(new GuardTransition(HealthKit::binding('ios'), GuardKind::DestinationQuality, $transition, 3));

    $base = ['connection' => 'ios', 'environment' => 'production', 'guard' => 'destination_quality', 'provider' => 'apns'];
    $by = mrxByName($rig->recorder);

    expect($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::TRANSITIONS, $base + ['transition' => $transition->value])))->toBe(1)
        ->and($rig->repo->get(Rig::seriesKey('s', MetricsRecorder::CIRCUIT_OPEN, $base)))->toBe($gauge)
        ->and($by[MetricsRecorder::TRANSITIONS][0]['labels'])->toBe($base + ['transition' => $transition->value])
        ->and($by[MetricsRecorder::TRANSITIONS][0]['type'])->toBe(MetricType::Counter)
        ->and($by[MetricsRecorder::CIRCUIT_OPEN][0]['labels'] ?? null)->toBe($gauge === null ? null : $base)
        ->and($rig->recorder->openCircuits())->toBe($gauge === 1 ? [$base] : []);
})->with([
    'opened' => [BreakerTransition::Opened, 1],
    'reopened' => [BreakerTransition::Reopened, 1],
    'half opened' => [BreakerTransition::HalfOpened, 1],
    'closed' => [BreakerTransition::Closed, 0],
    'none' => [BreakerTransition::None, null],
    'failed over' => [BreakerTransition::FailedOver, null],
]);

it('goes back to 0 when the circuit closes and tracks each guard of a connection on its own', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    $rig->recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Opened, 1));
    $rig->recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::DestinationQuality, BreakerTransition::HalfOpened, 1));

    expect(array_column($rig->recorder->openCircuits(), 'guard'))->toBe(['auth', 'destination_quality']);

    $rig->recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Closed, 1));

    expect(array_column($rig->recorder->openCircuits(), 'guard'))->toBe(['destination_quality']);
    $gauges = array_column(mrxByName($rig->recorder)[MetricsRecorder::CIRCUIT_OPEN], 'value', null);
    expect($gauges)->toBe([0, 1]);
    $counters = [];
    foreach (mrxByName($rig->recorder)[MetricsRecorder::TRANSITIONS] as $m) {
        $counters[$m['labels']['guard'].'/'.$m['labels']['transition']] = $m['value'];
    }
    expect($counters)->toBe(['auth/opened' => 1, 'destination_quality/half_opened' => 1, 'auth/closed' => 1]);
});

it('keeps an open circuit visible for a week and forgets it afterwards', function (): void {
    $rig = new Rig;
    $rig->recorder->recordGuardTransition(new GuardTransition(HealthKit::binding('ios'), GuardKind::Auth, BreakerTransition::Opened, 1));
    $gauge = Rig::seriesKey('s', MetricsRecorder::CIRCUIT_OPEN, ['connection' => 'ios', 'environment' => 'production', 'guard' => 'auth', 'provider' => 'apns']);

    $rig->advance(6 * 86400);
    expect($rig->repo->get($gauge))->toBe(1)->and($rig->recorder->openCircuits())->toHaveCount(1);

    $rig->advance(2 * 86400);
    expect($rig->repo->get($gauge))->toBeNull()->and($rig->recorder->openCircuits())->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// snapshot()
// ---------------------------------------------------------------------------------------------------------------------

it('reads the snapshot from the index: numbers as integers, window series skipped, unreadable values left out', function (): void {
    $rig = new Rig;
    $now = $rig->clock->now()->getTimestamp();
    $entry = static fn (string $k, string $n, array $l): array => ['k' => $k, 'n' => $n, 'l' => $l];
    $rig->repo->forever(Rig::indexKey(), json_encode([
        'k:string' => $entry('c', MetricsRecorder::RESULTS, ['a' => 'x']),
        'k:float' => $entry('c', MetricsRecorder::RESULTS, ['a' => 'y']),
        'k:custom' => $entry('c', 'custom_total', []),
        'k:circuit-big' => $entry('s', MetricsRecorder::CIRCUIT_OPEN, ['a' => 'z']),
        'k:text' => $entry('c', MetricsRecorder::RESULTS, ['a' => 'text']),
        'k:missing' => $entry('c', MetricsRecorder::RESULTS, ['a' => 'missing']),
        'k:window' => $entry('b', '', ['a' => 'w']),
        'k:future' => $entry('s', MetricsRecorder::ACCOUNT_THROTTLED, ['a' => 'future']),
        'k:past' => $entry('s', MetricsRecorder::ACCOUNT_THROTTLED, ['a' => 'past']),
        'k:now' => $entry('s', MetricsRecorder::ACCOUNT_THROTTLED, ['a' => 'now']),
        'k:zero' => $entry('c', MetricsRecorder::RESULTS, ['a' => 'zero']),
    ], JSON_THROW_ON_ERROR));
    $rig->repo->forever('k:string', '7');
    $rig->repo->forever('k:float', 7.9);
    $rig->repo->forever('k:custom', 3);
    $rig->repo->forever('k:circuit-big', 4_102_444_800);
    $rig->repo->forever('k:text', 'abc');
    $rig->repo->forever('k:window', 5);
    $rig->repo->forever('k:future', $now + 5);
    $rig->repo->forever('k:past', $now - 5);
    $rig->repo->forever('k:now', $now);
    $rig->repo->forever('k:zero', 0);

    $rows = array_map(static fn (Metric $m): array => [$m->name, $m->type, $m->labels, $m->value], $rig->recorder->snapshot());

    expect($rows)->toBe([
        [MetricsRecorder::RESULTS, MetricType::Counter, ['a' => 'x'], 7],
        [MetricsRecorder::RESULTS, MetricType::Counter, ['a' => 'y'], 7],
        ['custom_total', MetricType::Counter, [], 3],
        [MetricsRecorder::CIRCUIT_OPEN, MetricType::Gauge, ['a' => 'z'], 4_102_444_800],
        [MetricsRecorder::ACCOUNT_THROTTLED, MetricType::Gauge, ['a' => 'future'], 1],
        [MetricsRecorder::ACCOUNT_THROTTLED, MetricType::Gauge, ['a' => 'past'], 0],
        [MetricsRecorder::ACCOUNT_THROTTLED, MetricType::Gauge, ['a' => 'now'], 0],
        [MetricsRecorder::RESULTS, MetricType::Counter, ['a' => 'zero'], 0],
    ]);
    foreach ($rig->recorder->snapshot() as $m) {
        expect($m->value)->toBeInt();
    }
});

it('describes each metric and falls back to its own name for one it does not know', function (): void {
    $rig = new Rig;
    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);
    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);
    $rig->recorder->recordGuardTransition(new GuardTransition(HealthKit::binding('ios'), GuardKind::Auth, BreakerTransition::Opened, 1));
    $rig->repo->forever(Rig::indexKey(), json_encode($rig->index() + ['k:x' => ['k' => 'c', 'n' => 'mystery_total', 'l' => []]], JSON_THROW_ON_ERROR));
    $rig->repo->forever('k:x', 1);

    $help = [];
    foreach ($rig->recorder->snapshot() as $m) {
        $help[$m->name] = $m->help;
    }

    expect($help['mystery_total'])->toBe('mystery_total')
        ->and($help[MetricsRecorder::RESULTS])->toContain('Send outcomes')
        ->and($help[MetricsRecorder::THROTTLED])->toContain('paused by a provider throttle')
        ->and($help[MetricsRecorder::TRANSITIONS])->toContain('Circuit breaker transitions')
        ->and($help[MetricsRecorder::CIRCUIT_OPEN])->toContain('circuit of this guard is open')
        ->and($help[MetricsRecorder::ACCOUNT_THROTTLED])->toContain('paused by a provider throttle')
        ->and(count(array_unique($help)))->toBe(6);
});

it('answers an empty snapshot before anything was recorded and does not touch the series keys', function (): void {
    $rig = new Rig;

    expect($rig->recorder->snapshot())->toBe([])
        ->and($rig->store->log)->toBe(['get pfx:metrics:index']);
});

it('feeds the Prometheus text with the series, not with the window buckets', function (): void {
    $rig = new Rig;
    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);
    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);
    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);

    $text = (new PrometheusExporter($rig->recorder, static fn () => null, static fn () => null))->render();

    expect($text)->toContain('mobile_push_results_total{class="bulk",connection="ios",environment="production",provider="apns",reason="none",status="accepted"} 2')
        ->and($text)->toContain('mobile_push_throttled_total{class="bulk",connection="ios",environment="production",provider="apns"} 1')
        ->and($text)->toContain("# TYPE mobile_push_account_throttled gauge\n")
        ->and($text)->toContain("mobile_push_metrics_series 3\n");
});

// ---------------------------------------------------------------------------------------------------------------------
// errorRates()
// ---------------------------------------------------------------------------------------------------------------------

it('reads the minute buckets of the window: from the oldest minute of the window to the current one, clamped to 60 s..configured window', function (int $at, ?int $requested, int $sample, int $errors): void {
    $rig = new Rig(window: 300);
    $binding = HealthKit::binding('ios');
    // minute 0: 2 accepted + 1 failed; minute 1: 1 accepted
    $rig->record($binding, SendStatus::Accepted);
    $rig->record($binding, SendStatus::Accepted);
    $rig->record($binding, SendStatus::Failed, ReasonCode::ProviderInternal);
    $rig->advance(60);
    $rig->record($binding, SendStatus::Accepted);
    $rig->advance($at - 60);

    $rates = $rig->recorder->errorRates($requested);

    expect($rates)->toHaveCount(1)
        ->and([$rates[0]->sample, $rates[0]->errors])->toBe([$sample, $errors]);
})->with([
    'both minutes, default window' => [60, null, 4, 1],
    'minute 0 is the oldest of 5' => [240, null, 4, 1],
    'minute 0 fell out of the window' => [300, null, 1, 0],
    'everything fell out' => [360, null, 0, 0],
    'a 120 s window covers two minutes' => [60, 120, 4, 1],
    'a 120 s window one minute later' => [120, 120, 1, 0],
    'a 60 s window is the current minute only' => [60, 60, 1, 0],
    'below 60 s is raised to 60' => [60, 1, 1, 0],
    'zero is raised to 60' => [60, 0, 1, 0],
    'negative is raised to 60' => [60, -500, 1, 0],
    'above the configured window is cut to it' => [240, 99_999, 4, 1],
    'above the configured window, minute 0 is out' => [300, 99_999, 1, 0],
    'a window that is not a whole number of minutes loses the remainder' => [120, 179, 1, 0],
]);

it('limits the default window to the configured one when that is shorter than a request', function (): void {
    $rig = new Rig(window: 120);
    $binding = HealthKit::binding('ios');
    $rig->record($binding, SendStatus::Accepted);
    $rig->advance(60);
    $rig->record($binding, SendStatus::Failed, ReasonCode::ProviderInternal);
    $rig->advance(60);   // minute 0 is now 2 minutes old: outside a 120 s window

    $asked = static fn (?int $w): array => [$rig->recorder->errorRates($w)[0]->sample, $rig->recorder->errorRates($w)[0]->errors];

    expect($asked(null))->toBe([1, 1])
        ->and($asked(600))->toBe([1, 1])
        ->and($asked(60))->toBe([0, 0]);
});

it('gives one sample per binding, with the binding it belongs to and its own totals', function (): void {
    $rig = new Rig;
    $ios = HealthKit::binding('ios');
    $sandbox = HealthKit::binding('ios', Provider::Apns, Environment::Sandbox);
    $android = HealthKit::binding('android', Provider::Fcm);
    foreach ([$ios, $ios, $ios, $sandbox, $android, $android] as $b) {
        $rig->record($b, SendStatus::Accepted);
    }
    $rig->record($android, SendStatus::OutcomeUnknown, ReasonCode::TransportUnknown);
    $rig->record($ios, SendStatus::Rejected, ReasonCode::DestinationUnregistered);

    $rates = $rig->recorder->errorRates();

    expect($rates)->toHaveCount(3)
        ->and(array_map(static fn (ErrorRateSample $s): array => [$s->binding, $s->sample, $s->errors], $rates))->toBe([
            [['provider' => 'apns', 'connection' => 'ios', 'environment' => 'production'], 4, 0],
            [['provider' => 'apns', 'connection' => 'ios', 'environment' => 'sandbox'], 1, 0],
            [['provider' => 'fcm', 'connection' => 'android', 'environment' => 'production'], 3, 1],
        ]);
});

it('only lists connections that have a window series, and keeps one that fell silent', function (): void {
    $rig = new Rig;
    $rig->record(HealthKit::binding('quiet'), SendStatus::Skipped, ReasonCode::PolicyIneligible);
    $rig->recorder->recordThrottle(HealthKit::binding('throttled'), TrafficClass::Bulk);
    expect($rig->recorder->errorRates())->toBe([]);

    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);
    $rig->advance(3600);

    $rates = $rig->recorder->errorRates();
    expect($rates)->toHaveCount(1)->and([$rates[0]->sample, $rates[0]->errors])->toBe([0, 0]);
});

it('reads a window index entry that lost its labels and a bucket that is not a number', function (): void {
    $rig = new Rig;
    $rig->repo->forever(Rig::indexKey(), json_encode([
        'w:empty' => ['k' => 'b', 'n' => '', 'l' => []],
        'w:text' => ['k' => 'b', 'n' => '', 'l' => ['provider' => 'apns', 'connection' => 'ios', 'environment' => 'production']],
    ], JSON_THROW_ON_ERROR));
    $bucket = mrxBucket($rig);
    $rig->repo->forever(Rig::windowKey(Rig::labels('ios'), $bucket, 's'), 'abc');
    $rig->repo->forever(Rig::windowKey(Rig::labels('ios'), $bucket, 'e'), '2.5');

    $rates = $rig->recorder->errorRates();

    expect(array_map(static fn (ErrorRateSample $s): array => [$s->binding, $s->sample, $s->errors], $rates))->toBe([
        [['provider' => '', 'connection' => '', 'environment' => ''], 0, 0],
        [['provider' => 'apns', 'connection' => 'ios', 'environment' => 'production'], 0, 2],
    ]);
});

it('rates a connection from what errorRates reports, honouring the minimum sample', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    foreach (range(1, 6) as $_) {
        $rig->record($binding, SendStatus::Accepted);
    }
    foreach (range(1, 4) as $_) {
        $rig->record($binding, SendStatus::Failed, ReasonCode::ProviderUnavailable);
    }
    $sample = $rig->recorder->errorRates()[0];

    expect($sample->rate(10))->toBe(0.4)
        ->and($sample->rate(11))->toBeNull()
        ->and($sample->rate(0))->toBe(0.4);
});

// ---------------------------------------------------------------------------------------------------------------------
// reset()
// ---------------------------------------------------------------------------------------------------------------------

it('forgets every series and the index on reset, and starts counting from zero afterwards', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    $rig->record($binding, SendStatus::Accepted);
    $rig->record($binding, SendStatus::Accepted);
    $rig->recorder->recordThrottle($binding, TrafficClass::Bulk);
    $rig->recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Opened, 1));
    $keys = array_keys($rig->index());
    $bucket = mrxBucket($rig);
    expect(count($keys))->toBeGreaterThan(4);

    $rig->recorder->reset();

    foreach ($keys as $key) {
        expect($rig->repo->get($key))->toBeNull();
    }
    expect($rig->repo->get(Rig::indexKey()))->toBeNull()
        ->and($rig->recorder->snapshot())->toBe([])
        ->and($rig->recorder->errorRates())->toBe([])
        // the per-minute buckets are not forgotten, they expire by themselves
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('ios'), $bucket, 's')))->toBe(2);

    $rig->record($binding, SendStatus::Accepted);
    $snapshot = $rig->recorder->snapshot();
    expect($snapshot)->toHaveCount(1)->and($snapshot[0]->value)->toBe(1);
});

it('gives a series that was refused by the cap another chance after a reset', function (): void {
    $rig = new Rig(maxSeries: 2);
    $rig->record(HealthKit::binding('first'), SendStatus::Accepted);
    $rig->record(HealthKit::binding('second'), SendStatus::Accepted);
    expect(array_column(array_column($rig->recorder->snapshot(), 'labels'), 'connection'))->toBe(['first']);

    $rig->recorder->reset();
    $rig->record(HealthKit::binding('second'), SendStatus::Accepted);

    expect(array_column(array_column($rig->recorder->snapshot(), 'labels'), 'connection'))->toBe(['second']);
});

it('swallows a failing store on reset and still forgets what it remembered', function (): void {
    $rig = new Rig(maxSeries: 2);
    $rig->record(HealthKit::binding('first'), SendStatus::Accepted);
    $broken = new MetricsRecorder(new Repository(new DownStore), $rig->clock, true, Rig::PREFIX, 2);

    $broken->reset();

    expect($rig->recorder->snapshot())->toHaveCount(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// Series cap, rejected memo and the one-minute re-check
// ---------------------------------------------------------------------------------------------------------------------

it('never indexes more series than the cap, counts the ones it has and refuses the rest without reading the index again', function (): void {
    $rig = new Rig(maxSeries: 3);
    $rig->record(HealthKit::binding('c1'), SendStatus::Accepted);   // counter + window = 2 series
    $rig->record(HealthKit::binding('c2'), SendStatus::Accepted);   // counter = 3rd series, window refused
    $rig->record(HealthKit::binding('c3'), SendStatus::Accepted);   // both refused
    $bucket = mrxBucket($rig);
    $labels = static fn (string $c): array => ['class' => 'bulk', 'connection' => $c, 'environment' => 'production', 'provider' => 'apns', 'reason' => 'none', 'status' => 'accepted'];

    expect($rig->index())->toHaveCount(3)
        ->and($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels('c1'))))->toBe(1)
        ->and($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels('c2'))))->toBe(1)
        ->and($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels('c3'))))->toBeNull()
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('c1'), $bucket, 's')))->toBe(1)
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('c2'), $bucket, 's')))->toBeNull()
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('c3'), $bucket, 's')))->toBeNull()
        ->and(array_map(static fn (ErrorRateSample $s): string => $s->binding['connection'], $rig->recorder->errorRates()))->toBe(['c1']);

    $reads = $rig->store->count('get', Rig::indexKey());
    $rig->record(HealthKit::binding('c3'), SendStatus::Accepted);   // refused before: remembered, not looked up again
    $rig->record(HealthKit::binding('c1'), SendStatus::Accepted);   // known: not looked up again
    expect($rig->store->count('get', Rig::indexKey()))->toBe($reads)
        ->and($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels('c1'))))->toBe(2)
        ->and($rig->repo->get(Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels('c3'))))->toBeNull();
});

/*
 * A long-lived worker used to remember a refusal for its whole life: after another process had reset the metrics (or
 * the cache had been flushed) there was room again, but that worker went on dropping the series until it restarted.
 */
it('looks again a minute after the cap refused a series: room made elsewhere is used by a long-lived worker', function (): void {
    $rig = new Rig(maxSeries: 1);
    $rig->recorder->recordThrottle(HealthKit::binding('c1'), TrafficClass::Bulk);
    $rig->recorder->recordThrottle(HealthKit::binding('c2'), TrafficClass::Bulk);   // refused: the cap is one series
    $c2 = Rig::seriesKey('c', MetricsRecorder::THROTTLED, ['class' => 'bulk'] + Rig::labels('c2'));
    expect($rig->index())->toHaveCount(1)->and($rig->repo->get($c2))->toBeNull();

    $rig->repo->forget(Rig::indexKey());   // another process reset the metrics
    $rig->advance(59);
    $rig->recorder->recordThrottle(HealthKit::binding('c2'), TrafficClass::Bulk);   // still inside the minute: not looked up
    expect($rig->repo->get($c2))->toBeNull();

    $rig->advance(1);
    $rig->recorder->recordThrottle(HealthKit::binding('c2'), TrafficClass::Bulk);
    expect($rig->repo->get($c2))->toBe(1)
        ->and(array_keys($rig->index()))->toBe([$c2]);
});

it('applies the cap exactly: a cap of N allows N series and not N+1', function (int $cap): void {
    $rig = new Rig(maxSeries: $cap);
    foreach (range(1, 8) as $i) {
        $rig->recorder->recordThrottle(HealthKit::binding('c'.$i), TrafficClass::Bulk);
    }

    expect($rig->index())->toHaveCount($cap);
})->with([1, 2, 5]);

it('re-checks a known series in the index every 60 seconds, so a cache flush heals', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    $index = Rig::indexKey();
    $rig->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);
    expect($rig->recorder->snapshot())->toHaveCount(1);

    $rig->repo->flush();
    $reads = $rig->store->count('get', $index);
    $rig->advance(59);
    $rig->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);

    // 59 s: still trusted, so the counter is back but the index is not
    expect($rig->store->count('get', $index))->toBe($reads)
        ->and($rig->recorder->snapshot())->toBe([]);

    $rig->advance(1);
    $rig->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);
    $snapshot = $rig->recorder->snapshot();

    expect($snapshot)->toHaveCount(1)->and($snapshot[0]->value)->toBe(2);

    // the check renewed the trust: another record 30 s later does not read the index
    $reads = $rig->store->count('get', $index);
    $rig->advance(30);
    $rig->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);
    expect($rig->store->count('get', $index))->toBe($reads);
});

it('does not write the index again when the series is already in it, and does not take the lock', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    $rig->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);
    $writes = $rig->store->count('forever', Rig::indexKey());

    $other = new Rig($rig->store);   // another worker over the same cache
    $other->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);
    $rig->advance(60);
    $rig->record($binding, SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($rig->store->count('forever', Rig::indexKey()))->toBe($writes)
        ->and($rig->store->lockRequests)->toHaveCount(1)
        ->and($rig->recorder->snapshot()[0]->value)->toBe(3);
});

// ---------------------------------------------------------------------------------------------------------------------
// bump(): stores that do not create, contention, no double counting
// ---------------------------------------------------------------------------------------------------------------------

it('counts exactly once per result on a store whose increment does not create the key, and gives the counter a year to live', function (): void {
    $rig = new Rig;
    $rig->store->incrementCreates = false;
    $binding = HealthKit::binding('ios');
    $counter = Rig::seriesKey('c', MetricsRecorder::RESULTS, MRX_RESULTS_LABELS);

    foreach (range(1, 3) as $_) {
        $rig->record($binding, SendStatus::Accepted);
    }
    expect($rig->repo->get($counter))->toBe(3)
        ->and($rig->repo->get(Rig::windowKey(Rig::labels('ios'), mrxBucket($rig), 's')))->toBe(3);

    $rig->advance(300 * 86400);
    expect($rig->repo->get($counter))->toBe(3);

    $rig->advance(100 * 86400);   // 400 days: the year is over
    expect($rig->repo->get($counter))->toBeNull();
});

it('retries a contention error and counts the result once, on a store that creates and on one that does not', function (bool $creates): void {
    $rig = new Rig;
    $rig->store->incrementCreates = $creates;
    $rig->store->incrementErrors = [Rig::lockedError(), Rig::lockedError()];
    $binding = HealthKit::binding('ios');

    $rig->recorder->recordThrottle($binding, TrafficClass::Bulk);

    $counter = mrxByName($rig->recorder)[MetricsRecorder::THROTTLED][0];
    expect($counter['value'])->toBe(1);
})->with([true, false]);

it('gives a contended increment up after ten tries, without throwing and without counting', function (): void {
    $rig = new Rig;
    $rig->store->incrementErrors = array_fill(0, 50, Rig::lockedError());

    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);

    expect($rig->store->incrementCalls)->toBe(10)
        ->and($rig->recorder->snapshot())->toBe([]);
});

it('does not retry an error that is not contention', function (): void {
    $rig = new Rig;
    $rig->store->incrementErrors = [new RuntimeException('Connection refused'), new RuntimeException('again')];

    $rig->recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);

    expect($rig->store->incrementCalls)->toBe(1);
});

it('does not take a lock around an increment on a store that increments atomically, only for the index', function (): void {
    $rig = new Rig;
    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);

    expect(array_column($rig->store->lockRequests, 0))->toBe([Rig::indexKey().':lock', Rig::indexKey().':lock'])
        ->and(array_unique(array_column($rig->store->lockRequests, 1)))->toBe([10]);
});

it('serialises the read-modify-write increment of the file store with the store lock, and releases it', function (): void {
    $dir = sys_get_temp_dir().'/mp-metrics-'.bin2hex(random_bytes(4));
    $store = new class(new Filesystem, $dir) extends FileStore
    {
        /** @var list<array{string, int}> */
        public array $lockRequests = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->lockRequests[] = [$name, $seconds];

            return parent::lock($name, $seconds, $owner);
        }
    };
    $rig = new Rig;
    $recorder = new MetricsRecorder(new Repository($store), $rig->clock, true, Rig::PREFIX);
    $binding = HealthKit::binding('ios');

    try {
        foreach (range(1, 3) as $_) {
            $recorder->record($binding, HealthKit::result(SendStatus::Failed, ReasonCode::ProviderInternal), TrafficClass::Bulk);
        }
        $counter = Rig::seriesKey('c', MetricsRecorder::RESULTS, ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns', 'reason' => 'provider_internal', 'status' => 'failed']);
        $names = array_column($store->lockRequests, 0);

        expect($recorder->snapshot()[0]->value)->toBe(3)
            ->and($recorder->errorRates()[0]->errors)->toBe(3)
            ->and($names)->toContain($counter.':lock')
            ->and($names)->toContain(Rig::windowKey(Rig::labels('ios'), mrxBucket($rig), 's').':lock')
            ->and($names)->toContain(Rig::windowKey(Rig::labels('ios'), mrxBucket($rig), 'e').':lock');
        foreach (array_unique($names) as $name) {
            if ($name === Rig::indexKey().':lock') {
                continue;
            }
            $lock = $store->lock($name, 5);
            expect($lock->get())->toBeTrue();   // it was released
            $lock->release();
        }
        foreach ($store->lockRequests as [$name, $seconds]) {
            if (str_ends_with($name, ':lock') && $name !== Rig::indexKey().':lock') {
                expect($seconds)->toBe(5);
            }
        }
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('does not hold a send for long when somebody else holds the counter lock of the file store', function (): void {
    $dir = sys_get_temp_dir().'/mp-metrics-'.bin2hex(random_bytes(4));
    $store = new class(new Filesystem, $dir) extends FileStore
    {
        /** @var list<string> */
        public array $lockNames = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->lockNames[] = $name;

            return parent::lock($name, $seconds, $owner);
        }
    };
    $rig = new Rig;
    $recorder = new MetricsRecorder(new Repository($store), $rig->clock, true, Rig::PREFIX);
    $counter = Rig::seriesKey('c', MetricsRecorder::THROTTLED, ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns']);

    try {
        $held = $store->lock($counter.':lock', 30);
        expect($held->get())->toBeTrue();

        $started = microtime(true);
        $recorder->recordThrottle(HealthKit::binding('ios'), TrafficClass::Bulk);

        $waited = array_count_values($store->lockNames)[$counter.':lock'] - 1;   // minus the test's own attempt

        expect(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($waited)->toBe(100)   // five rounds of twenty attempts, about 100 ms each
            ->and($recorder->snapshot()[0]->value)->toBe(1)
            ->and($held->get())->toBeFalse();   // the foreign lock was not released by the recorder
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// withIndexLock()
// ---------------------------------------------------------------------------------------------------------------------

it('writes the index under a lock named after the index and releases it', function (): void {
    $rig = new Rig;
    $lock = new ScriptedLock([true]);
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($rig->store->lockRequests)->toBe([[Rig::indexKey().':lock', 10]])
        ->and($lock->gets)->toBe(1)
        ->and($lock->releases)->toBe(1)
        ->and($rig->recorder->snapshot())->toHaveCount(1);
});

it('still writes the index when the lock is never obtained, after trying fifty times, and has nothing to release', function (): void {
    $rig = new Rig;
    $lock = new ScriptedLock([false]);
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($lock->gets)->toBe(50)
        ->and($lock->releases)->toBe(0)
        ->and($rig->recorder->snapshot())->toHaveCount(1)
        ->and($rig->recorder->snapshot()[0]->value)->toBe(1);
});

it('reads a contention error while taking the lock as not taken, tries again and releases the lock it finally got', function (): void {
    $rig = new Rig;
    $lock = new ScriptedLock([Rig::lockedError(), Rig::lockedError(), true]);
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($lock->gets)->toBe(3)
        ->and($lock->releases)->toBe(1)
        ->and($rig->recorder->snapshot())->toHaveCount(1);
});

it('lets any other error of the lock backend stop the recording, never the send', function (): void {
    $rig = new Rig;
    $lock = new ScriptedLock([new RuntimeException('lock backend down')]);
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($lock->gets)->toBe(1)
        ->and($rig->repo->get(Rig::indexKey()))->toBeNull()
        ->and($rig->recorder->snapshot())->toBe([]);
});

it('writes the index on a store that cannot lock', function (): void {
    $store = new LocklessStore;
    $rig = new Rig;
    $recorder = new MetricsRecorder(new Repository($store), $rig->clock, true, Rig::PREFIX);

    $recorder->record(HealthKit::binding('ios'), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);
    $recorder->record(HealthKit::binding('ios'), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);

    expect($recorder->snapshot())->toHaveCount(1)
        ->and($recorder->snapshot()[0]->value)->toBe(2)
        ->and($recorder->errorRates()[0]->sample)->toBe(2);
});

it('keeps what another worker indexed while it waited for the lock', function (): void {
    $rig = new Rig;
    $theirs = ['k' => 'c', 'n' => MetricsRecorder::RESULTS, 'l' => ['connection' => 'theirs']];
    $lock = new ScriptedLock([true], function () use ($rig, $theirs): void {
        $rig->repo->forever(Rig::indexKey(), json_encode(['their:key' => $theirs], JSON_THROW_ON_ERROR));
    });
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    $index = $rig->index();
    expect(array_keys($index))->toHaveCount(2)
        ->and($index['their:key'])->toBe($theirs)
        ->and($rig->recorder->snapshot())->toHaveCount(1);
});

it('does not write the index again when the series appeared in it while it waited for the lock', function (): void {
    $rig = new Rig;
    $labels = ['class' => 'bulk', 'connection' => 'ios', 'environment' => 'production', 'provider' => 'apns', 'reason' => 'policy_ineligible', 'status' => 'skipped'];
    $key = Rig::seriesKey('c', MetricsRecorder::RESULTS, $labels);
    $lock = new ScriptedLock([true], function () use ($rig, $key, $labels): void {
        $rig->repo->forever(Rig::indexKey(), json_encode([$key => ['k' => 'c', 'n' => MetricsRecorder::RESULTS, 'l' => $labels]], JSON_THROW_ON_ERROR));
    });
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($rig->store->count('forever', Rig::indexKey()))->toBe(1)   // the other worker's write only
        ->and($rig->repo->get($key))->toBe(1)
        ->and($rig->recorder->snapshot()[0]->value)->toBe(1);
});

it('applies the cap to what is in the index once it holds the lock', function (): void {
    $rig = new Rig(maxSeries: 1);
    $lock = new ScriptedLock([true], function () use ($rig): void {
        $rig->repo->forever(Rig::indexKey(), json_encode(['their:key' => ['k' => 'c', 'n' => MetricsRecorder::RESULTS, 'l' => []]], JSON_THROW_ON_ERROR));
    });
    $rig->store->lockFactory = static fn () => $lock;

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect(array_keys($rig->index()))->toBe(['their:key'])
        ->and($rig->store->all())->toHaveCount(1)
        ->and($rig->recorder->snapshot())->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// loadIndex() tolerance
// ---------------------------------------------------------------------------------------------------------------------

it('treats a damaged index as empty everywhere and repairs it on the next record', function (mixed $stored): void {
    $rig = new Rig;
    $rig->repo->forever(Rig::indexKey(), $stored);

    expect($rig->recorder->snapshot())->toBe([])
        ->and($rig->recorder->errorRates())->toBe([])
        ->and($rig->recorder->openCircuits())->toBe([])
        ->and($rig->recorder->throttledAccounts())->toBe([]);

    $rig->record(HealthKit::binding('ios'), SendStatus::Skipped, ReasonCode::PolicyIneligible);

    expect($rig->recorder->snapshot())->toHaveCount(1)
        ->and($rig->index())->toHaveCount(1);
    $rig->recorder->reset();
    expect($rig->repo->get(Rig::indexKey()))->toBeNull();
})->with([
    'an integer' => [5],
    'an empty string' => [''],
    'not json' => ['{not json'],
    'a json string' => ['"text"'],
    'json null' => ['null'],
    'a list' => ['[1,2]'],
    'an entry under a numeric key' => ['{"7":{"k":"b","n":"","l":{"provider":"apns","connection":"x","environment":"production"}}}'],
    'an entry that is not an array' => ['{"a":"text"}'],
    'an entry without k' => ['{"a":{"n":"x","l":{}}}'],
    'an entry without n' => ['{"a":{"k":"c","l":{}}}'],
    'an entry without l' => ['{"a":{"k":"c","n":"x"}}'],
    'an entry with a numeric kind' => ['{"a":{"k":1,"n":"x","l":{}}}'],
    'an entry with a numeric name' => ['{"a":{"k":"c","n":2,"l":{}}}'],
    'an entry with labels that are not a map' => ['{"a":{"k":"c","n":"x","l":"nope"}}'],
]);

it('keeps from a stored entry only the labels that are text, under a text name, and the valid entries around a bad one', function (): void {
    $rig = new Rig;
    $rig->repo->forever(Rig::indexKey(), '{"bad":5,"good":{"k":"c","n":"x_total","l":{"a":"x","b":5,"c":null,"d":["z"],"7":"v"}},"also":{"k":"c","n":"y_total","l":{}}}');
    $rig->repo->forever('good', 4);
    $rig->repo->forever('also', 6);

    $rows = array_map(static fn (Metric $m): array => [$m->name, $m->labels, $m->value], $rig->recorder->snapshot());

    expect($rows)->toBe([['x_total', ['a' => 'x'], 4], ['y_total', [], 6]]);
});

// ---------------------------------------------------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------------------------------------------------

it('cleans the connection name into 64 printable ASCII characters and uses the same name for the window', function (): void {
    $rig = new Rig;
    $name = "Ünï\tcode".str_repeat('x', 100);
    $expected = substr('??n???code'.str_repeat('x', 100), 0, 64);

    $rig->record(HealthKit::binding($name), SendStatus::Accepted);

    expect($rig->recorder->snapshot()[0]->labels['connection'])->toBe($expected)
        ->and(strlen($expected))->toBe(64)
        ->and($rig->recorder->errorRates()[0]->binding['connection'])->toBe($expected)
        ->and($rig->recorder->errorRates()[0]->sample)->toBe(1);
});

it('never lets a token, a bearer, a bundle id or a scope reach the cache', function (): void {
    $rig = new Rig;
    $binding = HealthKit::binding('ios');
    $result = HealthKit::result(SendStatus::Rejected, ReasonCode::PayloadInvalid, Canary::TOKEN, Canary::BEARER);
    $rig->recorder->record($binding, $result, TrafficClass::Transactional);
    $rig->recorder->recordThrottle($binding, TrafficClass::Bulk);
    $rig->recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Opened, 9));

    $dump = json_encode($rig->store->all(), JSON_THROW_ON_ERROR);
    $allowed = ['provider', 'connection', 'environment', 'status', 'reason', 'class', 'guard', 'transition'];

    expect($dump)->not->toContain(Canary::TOKEN)
        ->and($dump)->not->toContain(Canary::BEARER)
        ->and($dump)->not->toContain('com.example.app')
        ->and($dump)->not->toContain('"default"');
    foreach ($rig->recorder->snapshot() as $metric) {
        expect(array_diff(array_keys($metric->labels), $allowed))->toBe([]);
    }
    foreach ($rig->index() as $entry) {
        expect(array_diff(array_keys($entry['l']), $allowed))->toBe([]);
    }
});

it('works with its defaults: prefix mobile-push, 1000 series, a 300 second window', function (): void {
    $rig = new Rig;
    $recorder = new MetricsRecorder($rig->repo, $rig->clock);
    foreach (range(1, 502) as $i) {
        $recorder->recordThrottle(HealthKit::binding('c'.$i), TrafficClass::Bulk);
    }

    $index = json_decode((string) $rig->repo->get('mobile-push:metrics:index'), true);
    expect($index)->toHaveCount(1000);

    $recorder->record(HealthKit::binding('ios'), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);
    $fresh = new MetricsRecorder($rig->repo, $rig->clock);
    $fresh->reset();
    $fresh->record(HealthKit::binding('ios'), HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);
    $rig->advance(240);
    expect($fresh->errorRates()[0]->sample)->toBe(1);
    $rig->advance(60);
    expect($fresh->errorRates()[0]->sample)->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// Never throws
// ---------------------------------------------------------------------------------------------------------------------

it('answers empty and does not throw on any method when the cache is down', function (): void {
    $recorder = new MetricsRecorder(new Repository(new DownStore), new FakeClock('2026-11-20T10:00:00Z'));
    $binding = HealthKit::binding('ios');

    $recorder->record($binding, HealthKit::result(SendStatus::Accepted), TrafficClass::Bulk);
    $recorder->recordThrottle($binding, TrafficClass::Bulk);
    $recorder->recordGuardTransition(new GuardTransition($binding, GuardKind::Auth, BreakerTransition::Opened, 1));
    $recorder->reset();

    expect($recorder->snapshot())->toBe([])
        ->and($recorder->errorRates())->toBe([])
        ->and($recorder->openCircuits())->toBe([])
        ->and($recorder->throttledAccounts())->toBe([]);
});

it('answers empty when only reading many keys fails', function (): void {
    $store = new class extends SpyStore
    {
        public bool $broken = false;

        public function many(array $keys)
        {
            if ($this->broken) {
                throw new RuntimeException('read failed');
            }

            return parent::many($keys);
        }
    };
    $rig = new Rig($store);
    $rig->record(HealthKit::binding('ios'), SendStatus::Accepted);
    expect($rig->recorder->snapshot())->toHaveCount(1)->and($rig->recorder->errorRates())->toHaveCount(1);

    $store->broken = true;

    expect($rig->recorder->snapshot())->toBe([])
        ->and($rig->recorder->errorRates())->toBe([])
        ->and($rig->recorder->openCircuits())->toBe([]);
});
