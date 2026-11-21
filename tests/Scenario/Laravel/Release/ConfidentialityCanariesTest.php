<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Telescope;
use Livewire\Livewire;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Console\Ops\OpsCommands;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Pulse\Livewire\MobilePushCard;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\CapturingHandler;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\RecordingTelemetry;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse\PulseWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Telescope\TelescopeWorld;

require_once dirname(__DIR__).'/Integration/IntegrationWorld.php';

/*
 * Release test table, row "confidentiality": made-up markers on a push that succeeds, one that fails and one that is
 * retried are absent from the logs, Pulse, Telescope and OpenTelemetry. After `forget` the old job neither recreates
 * the record nor contacts the provider.
 *
 * Story: Alice (Customer 4242) has three Android phones registered. The shop sends her three order notifications on
 * the durable profile with receipts on, every send-log sink on (`log` into a capturing Monolog channel, `database`,
 * `otel` into an in-memory tracer/meter provider) at payload level `full`, Pulse recording and Telescope recording.
 * FCM (the FakeTransport) accepts the first; refuses the second with 404 UNREGISTERED and an error message that echoes
 * the device token, the bearer and the receipt marker back; answers 503 UNAVAILABLE to the third, which is accepted on
 * the retry a minute later. Everything is real between `queue()` and the transport: ledger, relay, Sender, compiler,
 * classifier, retry planner, guards, receipt markers, sinks, events, Pulse recorder, Telescope watcher.
 *
 * The canaries: the three device tokens and the Google bearer are chosen by the test and look like nothing else; the
 * receipt markers are issued by the package (an HMAC), so they are read back from the request bodies the transport
 * received and searched for like the others.
 *
 * Then Alice asks to be forgotten while a fourth push to her is still queued. `mobile-push:forget` runs; the worker
 * then picks up that queued relay job and re-runs the old ones: no request reaches the provider, no row is recreated.
 *
 * Pulse (laravel/pulse) and Telescope (laravel/telescope) are dev dependencies of the package: when one is missing
 * from vendor/, its surface is skipped (said in the assertion list), never the story.
 */

beforeEach(function (): void {
    $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
});

afterEach(function (): void {
    if (class_exists(Telescope::class)) {
        // Telescope keeps "recording" in static state: left on, every later test of the run would pile its entries up
        // in memory (the long-lived runtime scenarios measure exactly that).
        Telescope::stopRecording();
        Telescope::flushEntries();
    }
});

/** Every encoding a leaked value could take in a dump. @return array<string, string> */
function canaryForms(string $canary): array
{
    return [
        'clear' => $canary,
        'base64' => base64_encode($canary),
        'base64url' => rtrim(strtr(base64_encode($canary), '+/', '-_'), '='),
        'json' => trim((string) json_encode($canary), '"'),
        'url' => rawurlencode($canary),
        'hex' => bin2hex($canary),
    ];
}

/** @return list<string> every leak found: "<what> (<form>) in <surface>" */
function leaksIn(array $surfaces, array $canaries): array
{
    $leaks = [];
    foreach ($surfaces as $surface => $text) {
        foreach ($canaries as $what => $canary) {
            foreach (canaryForms($canary) as $form => $value) {
                if (str_contains($text, $value)) {
                    $leaks[] = "{$what} ({$form}) in {$surface}";
                }
            }
        }
    }

    return $leaks;
}

/** The value of the first `mp_r` key anywhere in a JSON body. */
function markerIn(array $body): ?string
{
    foreach ($body as $key => $value) {
        if ($key === 'mp_r' && is_string($value)) {
            return $value;
        }
        if (is_array($value) && ($found = markerIn($value)) !== null) {
            return $found;
        }
    }

    return null;
}

/** @return array<string, string> table => every row as JSON */
function tableDumps(array $tables): array
{
    $dumps = [];
    foreach ($tables as $table) {
        $dumps['table '.$table] = json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
    }

    return $dumps;
}

/**
 * The whole story, with its oracles. Returns the id of the effect queued to Alice just before she was forgotten.
 */
function confidentialityStory(): string
{
    $pulse = class_exists(Pulse::class) && class_exists(Livewire::class);
    $telescope = class_exists(Telescope::class);

    Queue::fake();
    $telemetry = new RecordingTelemetry;
    app()->instance(TracerProviderInterface::class, $telemetry);
    app()->instance(MeterProviderInterface::class, $telemetry);
    CapturingHandler::$records = [];
    config()->set('logging.channels.mp_canary', ['driver' => 'monolog', 'handler' => CapturingHandler::class, 'level' => 'debug']);

    $world = IntegrationWorld::install(['connections' => ['android' => W::fcm()]])
        ->withDatabaseLog()
        ->withDurableLedger(receipts: true);
    $world->send->withDevices();
    config()->set('mobile-push.log.sinks', ['log', 'database', 'otel']);
    config()->set('mobile-push.log.channel', 'mp_canary');
    config()->set('mobile-push.log.payload', 'full');
    IntegrationWorld::forget();
    Carbon::setTestNow(Carbon::instance($world->send->clock->now()));
    if ($pulse) {
        PulseWorld::build(app());
    }
    if ($telescope) {
        TelescopeWorld::build(app());
    }

    $suffix = bin2hex(random_bytes(12));
    $tokens = [
        'accepted token' => 'CANARY-DEVICE-TOKEN-ACCEPTED-'.$suffix,
        'rejected token' => 'CANARY-DEVICE-TOKEN-REJECTED-'.$suffix,
        'retried token' => 'CANARY-DEVICE-TOKEN-RETRIED-'.$suffix,
    ];
    $bearer = 'ya29.CANARY-BEARER-'.$suffix;
    $world->send->nextBearer = $bearer;

    $alice = Customer::withId(4242);
    $binding = $world->send->manager()->binding('android');
    /** @var array<string, PushDevice> $devices */
    $devices = [];
    foreach ($tokens as $what => $token) {
        $devices[$what] = app(DeviceRegistrar::class)->register($binding, FcmToken::of($token), $alice);
    }

    // FCM: the second phone is gone (and the error text echoes everything back); the third is unavailable once.
    $markers = [];
    $retriedOnce = false;
    $world->send->answer = static function (Request $request) use (&$markers, &$retriedOnce, $tokens, $bearer): ?Response {
        $body = IntegrationWorld::body($request);
        $token = (string) ($body['message']['token'] ?? '');
        $marker = markerIn($body);
        if ($marker !== null) {
            $markers[] = $marker;
        }
        $echo = "token {$token}, authorization Bearer {$bearer}, marker {$marker}";
        if ($token === $tokens['rejected token']) {
            return new Response(404, ['content-type' => 'application/json'], (string) json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found: '.$echo,
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]));
        }
        if ($token === $tokens['retried token'] && ! $retriedOnce) {
            $retriedOnce = true;

            return new Response(503, ['content-type' => 'application/json'], (string) json_encode(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'The service is currently unavailable: '.$echo,
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNAVAILABLE']]]]));
        }

        return null;   // 200 with a message name
    };

    $ran = new SplObjectStorage;
    $work = static function () use ($ran, $world): void {   // a queue worker: every job whose delay is over
        foreach (Queue::pushed(RelayDeliveriesJob::class) as $job) {
            if ($ran->contains($job) || ($job->followUpAt !== null && $job->followUpAt > $world->send->clock->now()->getTimestamp())) {
                continue;
            }
            $ran->attach($job);
            app()->call([$job, 'handle']);
        }
    };

    $effects = [];
    foreach (['accepted token' => 'order-1', 'rejected token' => 'order-2', 'retried token' => 'order-3'] as $what => $key) {
        $effects[$what] = MobilePush::connection('android')->to($devices[$what]->destination())->effectKey($key)
            ->queue(PushMessage::make('Order '.$key, 'It shipped')->data(['order' => $key])->ttl(7200));
    }
    $work();
    $world->send->clock->advance(5 * 60);   // FCM UNAVAILABLE: at least 60 s (docs/retries-and-limits.md)
    Carbon::setTestNow(Carbon::instance($world->send->clock->now()));
    $work();

    $states = DB::table('mobile_push_deliveries')->pluck('state', 'effect_id')->all();
    $attempts = DB::table('mobile_push_deliveries')->pluck('attempts', 'effect_id')->map(static fn ($n): int => (int) $n)->all();
    expect([$states[$effects['accepted token']], $states[$effects['rejected token']], $states[$effects['retried token']]])->toBe(['accepted', 'rejected', 'accepted'])
        ->and($attempts[$effects['retried token']])->toBe(2)
        ->and($world->send->issuedBearers)->toContain($bearer)
        ->and($markers)->toHaveCount(4)   // one per request, the retry included
        ->and(count(array_unique($markers)))->toBeGreaterThanOrEqual(3);

    // ---------------------------------------------------------------- every surface, as an operator could read it
    app(SinkManager::class)->flush();
    $surfaces = ['log channel' => CapturingHandler::dump(), 'otel spans and metrics' => $telemetry->everything()];
    // Columns that legitimately hold ciphertext: mobile_push_devices.token and .fid (encrypted envelopes),
    // mobile_push_effects.message (encrypted snapshot), mobile_push_deliveries.destination (encrypted raw value, NULL
    // for registry devices as here). Keyed HMAC indexes: devices.token_index / fid_index, logs.destination_index,
    // deliveries.logical_key. Masked: logs.destination_masked. No column may hold a clear (or merely encoded) canary.
    $surfaces += tableDumps(['mobile_push_logs', 'mobile_push_deliveries', 'mobile_push_effects', 'mobile_push_devices']);
    $surfaces['queued jobs'] = serialize(Queue::pushed(RelayDeliveriesJob::class)->all());
    $cache = app('cache')->store();
    if ($cache instanceof Repository && $cache->getStore() instanceof ArrayStore) {
        $surfaces['package cache (array store)'] = serialize((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($cache->getStore()));
    }
    if ($pulse) {
        Pulse::ingest();
        $surfaces += tableDumps(['pulse_entries', 'pulse_aggregates', 'pulse_values']);
        Livewire::withoutLazyLoading();
        $surfaces['pulse card html'] = Livewire::test(MobilePushCard::class)->html();
    }
    if ($telescope) {
        $repository = app(EntriesRepository::class);
        Telescope::store($repository);
        $surfaces['telescope entries'] = json_encode($repository->get(null, (new EntryQueryOptions)->limit(1000)), JSON_THROW_ON_ERROR);
        $surfaces['telescope tables'] = json_encode([
            DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries')->get()->all(),
            DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries_tags')->get()->all(),
        ], JSON_THROW_ON_ERROR);
    }

    // The surfaces really saw the three pushes (an empty surface would prove nothing).
    expect(count(CapturingHandler::$records))->toBeGreaterThanOrEqual(3)
        ->and(count($telemetry->spans))->toBeGreaterThanOrEqual(3)
        ->and(DB::table('mobile_push_logs')->count())->toBeGreaterThanOrEqual(3)
        ->and($surfaces['table mobile_push_logs'])->toContain('unregistered')->toContain('[redacted');
    if ($pulse) {
        expect(DB::table('pulse_entries')->count())->toBeGreaterThan(0);
    }
    if ($telescope) {
        expect($surfaces['telescope entries'])->toContain('mobile-push.result');
    }

    $canaries = $tokens + ['bearer' => $bearer];
    foreach (array_values(array_unique($markers)) as $i => $marker) {
        $canaries['receipt marker '.($i + 1)] = $marker;
    }
    // Positive control: the search does find every canary where it legitimately is, on the wire to the provider.
    $wire = json_encode(array_map(static fn (Request $r): array => [$r->headers, $r->body], $world->send->transport->requests()), JSON_THROW_ON_ERROR);
    foreach ($canaries as $what => $canary) {
        expect(leaksIn(['wire' => $wire], [$what => $canary]))->not->toBe([], "the search must find the {$what} on the wire");
    }

    expect(leaksIn($surfaces, $canaries))->toBe([]);

    // Ciphertext columns are ciphertext, not an encoding of the clear value.
    $cipher = array_merge(
        DB::table('mobile_push_devices')->pluck('token')->all(),
        DB::table('mobile_push_effects')->pluck('message')->all(),
        DB::table('mobile_push_deliveries')->pluck('destination')->all(),
    );
    $decoded = implode("\n", array_map(static fn ($v): string => (string) base64_decode(strtr((string) $v, '-_', '+/'), false), array_filter($cipher)));
    expect(leaksIn(['base64-decoded ciphertext columns' => $decoded], $canaries))->toBe([]);

    // ---------------------------------------------------------------- forget, then the old jobs run again
    $sentBefore = count($world->send->pushRequests());
    // A fourth push, to Alice herself (her active phones), is queued and not yet picked up by a worker.
    $pending = MobilePush::connection('android')->to($alice)->effectKey('order-4')
        ->queue(PushMessage::make('Order order-4', 'It shipped')->ttl(7200));
    expect(DB::table('mobile_push_deliveries')->where('effect_id', $pending)->pluck('state')->all())->toBe(['pending', 'pending']);
    $rowsBefore = DB::table('mobile_push_deliveries')->count();

    if (! array_key_exists('mobile-push:forget', Artisan::all())) {
        foreach (OpsCommands::all() as $class) {
            Artisan::registerCommand(app($class));
        }
    }
    $exit = Artisan::call('mobile-push:forget', ['owner' => Customer::class.':4242', '--force' => true, '--no-interaction' => true]);
    $forgetOutput = Artisan::output();
    $logRowsAfterForget = DB::table('mobile_push_logs')->count();

    $work();                                                               // the queued relay job of order-4
    foreach (Queue::pushed(RelayDeliveriesJob::class) as $old) {          // and every old relay job, again
        app()->call([$old, 'handle']);
    }
    app(SinkManager::class)->flush();

    expect($exit)->toBe(0)
        ->and(count($world->send->pushRequests()))->toBe($sentBefore)       // the provider was never contacted again
        ->and(DB::table('mobile_push_deliveries')->count())->toBe($rowsBefore)
        ->and(DB::table('mobile_push_deliveries')->where('effect_id', $pending)->pluck('state')->all())->toBe(['cancelled', 'cancelled'])
        ->and(DB::table('mobile_push_deliveries')->whereNotNull('owner_id')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->whereNotNull('destination')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->whereNotNull('device_id')->count())->toBe(0)
        ->and(DB::table('mobile_push_devices')->count())->toBe(0)
        ->and(DB::table('mobile_push_logs')->count())->toBe($logRowsAfterForget)
        ->and(DB::table('mobile_push_logs')->where('origin_id', '4242')->count())->toBe(0)
        ->and(leaksIn(['forget output' => $forgetOutput], $canaries))->toBe([]);

    return $pending;
}

it('confidentiality: tokens, bearer and receipt markers of an accepted, a rejected and a retried push reach no log, table, span, metric, Pulse or Telescope; after forget the old jobs send nothing and recreate nothing', function () {
    confidentialityStory();
});

it('forget erases the message snapshot of an effect queued to the forgotten owner', function () {
    $pending = confidentialityStory();

    // docs/security-and-privacy.md (`forget`, step "Ledger: deliveries erased"): "the encrypted message snapshot and
    // the notifiable of the effects that originate from the owner are erased". The fourth push was queued to Alice
    // herself (`->to($alice)`), so its effect originates from her and its snapshot must be gone.
    $effect = DB::table('mobile_push_effects')->where('id', $pending)->first(['origin_type', 'origin_id', 'message']);
    expect($effect->message)->toBeNull('the message snapshot of the effect queued to the forgotten owner is still stored (origin_type='
        .var_export($effect->origin_type, true).', origin_id='.var_export($effect->origin_id, true).')');
});
