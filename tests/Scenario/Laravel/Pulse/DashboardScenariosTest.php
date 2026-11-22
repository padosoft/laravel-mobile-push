<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Pulse\Livewire\MobilePushCard;
use Padosoft\MobilePush\Laravel\Pulse\MobilePushRecorder;
use Padosoft\MobilePush\Laravel\Pulse\PulseModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse\Canary;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse\Kit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse\PulseWorld;

beforeEach(function () {
    PulseWorld::build($this->app);
    Livewire::withoutLazyLoading();
});

function pulseRows(string $table = 'pulse_aggregates'): Collection
{
    return DB::table($table)->get();
}

it('shows a day of traffic on the dashboard: counters aggregate and the card lists totals and top reasons', function () {
    $apns = Kit::binding('ios-main');
    $fcm = Kit::binding('android-main', Provider::Fcm);

    // 300 transactional results (per-result events) plus the batch summaries every batch dispatches.
    for ($batch = 0; $batch < 20; $batch++) {
        event(Kit::batch($apns, ['accepted' => 8, 'rejected' => 1, 'outcome_unknown' => 1], 90 + $batch));
        event(Kit::batch($fcm, ['accepted' => 6, 'failed' => 2, 'deferred' => 1, 'skipped' => 1], 200 + $batch));
    }
    for ($i = 0; $i < 20; $i++) {
        event(Kit::result($apns, SendStatus::Rejected, ReasonCode::DestinationUnregistered));
        event(Kit::result($fcm, SendStatus::Failed, ReasonCode::ProviderUnavailable));
        event(Kit::result($fcm, SendStatus::Failed, ReasonCode::ProviderUnavailable));
        event(Kit::result($apns, SendStatus::Accepted));
    }
    event(Kit::throttled($fcm));
    event(Kit::circuitOpened($apns));
    Pulse::ingest();

    expect((float) pulseRows()->where('type', MobilePushRecorder::OUTCOME)->where('aggregate', 'sum')->where('period', 60)
        ->where('key', json_encode(['apns', 'ios-main', 'production', 'accepted']))->sum('value'))->toBe(160.0);

    Livewire::test(MobilePushCard::class, ['period' => '1_hour'])
        ->assertSee('Mobile push')
        ->assertSee('apns / ios-main')
        ->assertSee('fcm / android-main')
        ->assertSee('160')            // accepted on ios-main
        ->assertSee('provider_unavailable')
        ->assertSee('destination_unregistered')
        ->assertSee('80%')            // 160 / (160 + 20 + 20)
        ->assertSee('Open circuits')
        ->assertSee('apns / ios-main (auth)')
        ->assertSee('fcm / android-main');

    $recorder = app(MobilePushRecorder::class);
    expect($recorder->failures())->toBe(0);
});

it('renders an empty state with no data', function () {
    Livewire::test(MobilePushCard::class)
        ->assertSee('Mobile push')
        ->assertSee('No results');
});

it('keeps Pulse keys bounded however many devices and messages go through', function () {
    $binding = Kit::binding('ios-main');
    for ($i = 0; $i < 1000; $i++) {
        event(Kit::result($binding, SendStatus::Rejected, ReasonCode::DestinationRejected, token: 'device-'.$i.str_repeat('Zq9', 20), messageId: 'msg-'.$i));
        event(Kit::batch($binding, ['accepted' => 1, 'rejected' => 1]));
    }
    Pulse::ingest();

    $keys = pulseRows('pulse_entries')->pluck('key')->unique();
    expect($keys->count())->toBeLessThanOrEqual(12);
    foreach ($keys as $key) {
        expect($key)->not->toContain('device-')->not->toContain('msg-');
    }
    expect(pulseRows('pulse_aggregates')->pluck('key')->unique()->count())->toBeLessThanOrEqual(12);
});

it('folds an unbounded number of connection names into a bounded set of keys', function () {
    config()->set('mobile-push.pulse.max_connections', 5);
    for ($i = 0; $i < 200; $i++) {
        event(Kit::batch(Kit::binding('tenant-'.$i), ['accepted' => 1]));
    }
    Pulse::ingest();

    $keys = pulseRows('pulse_entries')->where('type', MobilePushRecorder::OUTCOME)->pluck('key')->unique();
    expect($keys->count())->toBe(6); // five named connections and "_other"
    expect($keys->contains(json_encode(['apns', '_other', 'production', 'accepted'])))->toBeTrue();
});

it('never lets a token, bearer or raw provider text reach Pulse storage or the rendered card', function () {
    $binding = Kit::binding('ios-main');
    event(Kit::result(
        $binding,
        SendStatus::Rejected,
        ReasonCode::DestinationRejected,
        token: Canary::TOKEN,
        raw: 'bad token '.Canary::TOKEN.' bearer '.Canary::BEARER.' '.Canary::RAW,
        hint: 'check the environment',
        messageId: 'provider-msg-1',
    ));
    event(Kit::batch($binding, ['rejected' => 1]));
    Pulse::ingest();

    // On MySQL and MariaDB Pulse keeps a binary hash of each key next to the key: not text, and not what is looked for.
    $dump = json_encode([
        pulseRows('pulse_entries'), pulseRows('pulse_aggregates'), pulseRows('pulse_values'),
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    $html = Livewire::test(MobilePushCard::class)->html();

    expect(pulseRows('pulse_entries')->count())->toBeGreaterThan(0);
    foreach (Canary::all() as $canary) {
        expect($dump)->not->toContain($canary);
        expect($html)->not->toContain($canary);
    }
    expect($dump)->not->toContain('provider-msg-1')->not->toContain('CANARY');
});

it('survives a broken Pulse storage: sending is never affected', function () {
    $attempts = new ArrayObject;
    $this->app->bind(Storage::class, fn () => new class($attempts) implements Storage
    {
        public function __construct(private ArrayObject $attempts) {}

        public function store(Collection $items): void
        {
            $this->attempts[] = $items->count();

            throw new RuntimeException('storage down');
        }

        public function trim(): void {}

        public function purge(?array $types = null): void {}

        public function values(string $type, ?array $keys = null): Collection
        {
            throw new RuntimeException('storage down');
        }

        public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection
        {
            throw new RuntimeException('storage down');
        }

        public function aggregate(string $type, array|string $aggregates, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
        {
            throw new RuntimeException('storage down');
        }

        public function aggregateTypes(array|string $types, string $aggregate, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
        {
            throw new RuntimeException('storage down');
        }

        public function aggregateTotal(array|string $types, string $aggregate, CarbonInterval $interval): float|Collection
        {
            throw new RuntimeException('storage down');
        }
    });
    config()->set('pulse.ingest.buffer', 1); // every record flushes straight to the (broken) storage

    $binding = Kit::binding();
    event(Kit::batch($binding, ['accepted' => 3]));
    event(Kit::result($binding, SendStatus::Rejected, ReasonCode::PayloadInvalid));
    event(Kit::circuitOpened($binding));

    // Reaching this line is the point: nothing was thrown into the sender. The storage really was hit and did fail.
    expect(count($attempts))->toBeGreaterThan(0);
});

it('records nothing when the module is disabled', function () {
    config()->set('mobile-push.pulse.enabled', false);

    event(Kit::batch(Kit::binding(), ['accepted' => 5]));
    event(Kit::throttled(Kit::binding()));
    Pulse::ingest();

    expect(pulseRows('pulse_entries')->where('type', 'like', 'mobile_push_%')->count())->toBe(0)
        ->and(pulseRows('pulse_values')->count())->toBe(0);
});

it('records nothing inside Pulse::ignore', function () {
    Pulse::ignore(fn () => event(Kit::batch(Kit::binding(), ['accepted' => 5])));
    Pulse::ingest();

    expect(pulseRows('pulse_entries')->count())->toBe(0);
});

it('samples with the configured rate and the card scales the figures back', function () {
    config()->set('mobile-push.pulse.sample_rate', 0.0);
    event(Kit::batch(Kit::binding(), ['accepted' => 5]));
    Pulse::ingest();
    expect(pulseRows('pulse_entries')->count())->toBe(0);

    config()->set('mobile-push.pulse.sample_rate', 0.5);
    $recorder = new MobilePushRecorder(app(Laravel\Pulse\Pulse::class), app('config'), fn () => 0.1);
    $recorder->record(Kit::batch(Kit::binding(), ['accepted' => 5]));
    Pulse::ingest();

    Livewire::test(MobilePushCard::class)->assertSee('~10'); // 5 kept at a 0.5 rate stand for ~10
});

it('shows throttled providers only while the throttle lasts, and closed circuits disappear', function () {
    $binding = Kit::binding('ios-main');
    event(Kit::circuitOpened($binding));
    event(Kit::circuitClosed($binding));
    event(Kit::throttled($binding, -10));
    event(Kit::throttled(Kit::binding('fcm-main', Provider::Fcm), 600));
    event(Kit::batch($binding, ['accepted' => 1], class: TrafficClass::Transactional));
    Pulse::ingest();

    $html = Livewire::test(MobilePushCard::class)->html();
    expect($html)->toContain('fcm / fcm-main')->not->toContain('apns / ios-main (auth)');
});

it('counts a failure inside the recorder instead of throwing', function () {
    $pulse = Mockery::mock(Laravel\Pulse\Pulse::class);
    $pulse->shouldReceive('record')->andThrow(new RuntimeException('boom'));
    $recorder = new MobilePushRecorder($pulse, app('config'));

    $recorder->record(Kit::batch(Kit::binding(), ['accepted' => 1]));
    $recorder->record(Kit::result(Kit::binding(), SendStatus::Rejected, ReasonCode::PayloadInvalid));

    expect($recorder->failures())->toBe(2);
});

it('can be dropped into a dashboard view with the documented tag', function () {
    event(Kit::batch(Kit::binding('ios-main'), ['accepted' => 4]));
    Pulse::ingest();

    $html = Blade::render('<livewire:mobile-push.pulse-card cols="full" />');

    expect($html)->toContain('Mobile push')->toContain('apns / ios-main')->toContain('col-span-full')
        // the rules the card brings itself, because Pulse's stylesheet has no such utility classes
        ->toContain('mobile-push-card-table')->toContain('mobile-push-card-grid');
});

it('shows the same numbers on the second poll, read back from a cache store that refuses to rebuild objects', function () {
    // What a freshly created Laravel 13 application has: a store that really serialises, and no class allowed back.
    $dir = sys_get_temp_dir().'/mp-pulse-cache-'.bin2hex(random_bytes(5));
    config()->set('cache.serializable_classes', false);
    config()->set('cache.stores.strict-file', ['driver' => 'file', 'path' => $dir, 'lock_path' => $dir]);
    config()->set('cache.default', 'strict-file');
    app('cache')->forgetDriver('strict-file');
    event(Kit::batch(Kit::binding('ios-main'), ['accepted' => 4]));
    Pulse::ingest();

    try {
        $first = Livewire::withoutLazyLoading()->test(MobilePushCard::class)->html();
        $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)), false);
        $second = Livewire::withoutLazyLoading()->test(MobilePushCard::class)->html();   // within the cache lifetime: answered by the store
    } finally {
        foreach (array_reverse(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST), false)) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    expect($files)->not->toBeEmpty()   // the card data really went through the store
        ->and($first)->toContain('apns / ios-main')
        ->and($second)->toContain('apns / ios-main');
});

it('publishes its view under the mobile-push-views tag', function () {
    $paths = ServiceProvider::pathsToPublish(null, PulseModule::PUBLISH_TAG);

    expect(array_keys($paths))->toHaveCount(1)
        ->and(array_keys($paths)[0])->toEndWith('resources/views/pulse')
        ->and(array_values($paths)[0])->toEndWith('views/vendor/mobile-push/pulse');
});
