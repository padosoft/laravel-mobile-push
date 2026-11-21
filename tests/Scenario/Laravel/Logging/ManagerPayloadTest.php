<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once dirname(__DIR__).'/Integration/IntegrationWorld.php';

/*
 * `log.payload` on the real send path (manager -> Sender -> observer -> sinks): the payload the provider received,
 * as the send log may show it at each level. Tokens, bearers and receipt markers never appear, even when the app
 * puts the device token in its own data.
 */

function payloadWorld(string $level, bool $durable = false): IntegrationWorld
{
    config()->set('mobile-push.log.payload', $level);
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]])->withDatabaseLog();

    return $durable ? $world->withDurableLedger(receipts: true) : $world;
}

/** @return list<mixed> the decoded payload column of every log row */
function loggedPayloads(): array
{
    return array_map(static fn (array $row): mixed => $row['payload'] === null ? null : json_decode((string) $row['payload'], true), IntegrationWorld::logRows());
}

it('logs no payload at level none (the default)', function () {
    payloadWorld('none');
    MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('n')))->send(PushMessage::make('Hello Mario', 'Your order shipped')->data(['order' => 'A-123']))->count();

    $rows = IntegrationWorld::logRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['payload'])->toBeNull()
        ->and($rows[0]['payload_bytes'])->toBeGreaterThan(0);
});

it('logs only the key names of what APNs and FCM received at level keys', function () {
    payloadWorld('keys');
    $message = PushMessage::make('Hello Mario', 'Your order shipped')->data(['order' => 'A-123']);
    MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('k')))->send($message)->count();
    MobilePush::connection('android')->to(FcmToken::of('fake-fcm-token-keys-level-0001'))->send($message)->count();

    [$ios, $android] = loggedPayloads();
    $dump = json_encode([$ios, $android], JSON_THROW_ON_ERROR);
    expect($ios)->toContain('aps.alert.title', 'aps.alert.body', 'order')
        ->and($android)->toContain('message.token', 'message.notification.title', 'message.data.order')
        ->and($dump)->not->toContain('Hello Mario')->not->toContain('A-123')->not->toContain('fake-fcm-token-keys-level-0001');
});

it('logs the redacted payload at level full, and a token or marker next to the data never appears', function () {
    $world = payloadWorld('full', durable: true);
    $token = W::apnsToken('canary-phone');
    $fcm = 'fake-fcm-token-canary-full-level-0001';
    // Each app copies its own device token into the data, right next to the order (and, on iOS, the mp_r marker).
    $message = static fn (string $own): PushMessage => PushMessage::make('Hello Mario', 'Your order shipped')->data(['order' => 'A-123', 'debug_token' => $own]);

    MobilePush::connection('ios')->to(ApnsDeviceToken::of($token))->queue($message($token));   // durable + receipts: an mp_r marker
    MobilePush::connection('android')->to(FcmToken::of($fcm))->send($message($fcm))->count();

    $request = $world->send->requestsTo('api.push.apple.com')[0];
    $marker = IntegrationWorld::body($request)['mp_r'] ?? null;
    expect($marker)->toBeString()->not->toBe('');

    $payloads = loggedPayloads();
    $dump = json_encode($payloads, JSON_THROW_ON_ERROR);
    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['aps']['alert']['title'])->toBe('Hello Mario')
        ->and($payloads[0]['order'])->toBe('A-123')
        ->and($payloads[1]['message']['data']['order'])->toBe('A-123')
        ->and($dump)->not->toContain($token)
        ->and($dump)->not->toContain($fcm)
        ->and($dump)->not->toContain($marker)
        ->and($dump)->not->toContain(substr($request->headers['authorization'], 7))
        ->and($dump)->toContain('[redacted]');
});

/** A sink that only counts (the oracle stays flat). */
final class CountingPayloadSink implements \Padosoft\MobilePush\Laravel\Logging\LogSink
{
    public int $records = 0;

    public int $withPayload = 0;

    public function write(\Padosoft\MobilePush\Laravel\Logging\LogRecord ...$records): void
    {
        foreach ($records as $record) {
            $this->records++;
            $this->withPayload += $record->payload === null ? 0 : 1;
        }
    }

    public function flush(): void {}
}

/**
 * 100,000 lazily generated destinations through the manager, the observers and (optionally) a counting sink at the
 * given payload level; the transport forgets what it recorded every 1000 requests (same approach as
 * tests/Scenario/Send/MillionDestinationsTest.php).
 *
 * @return array{growth: int, results: int, sink: CountingPayloadSink|null}
 */
function payloadVolume(?string $level, int $n = 100_000): array
{
    $sink = null;
    config()->set('mobile-push.log.sinks', []);
    if ($level !== null) {
        $sink = new CountingPayloadSink;
        \Padosoft\MobilePush\Laravel\Logging\SinkManager::extend('counting', static fn () => $sink);
        config()->set('mobile-push.log.sinks', ['counting']);
        config()->set('mobile-push.log.payload', $level);
    }
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $transport = $world->send->transport;
    $requests = 0;
    $transport->respondUsing(static function () use (&$requests, $transport): \Padosoft\MobilePush\Core\Transport\Response {
        if (++$requests % 1000 === 0) {
            $transport->reset();
        }

        return new \Padosoft\MobilePush\Core\Transport\Response(200);
    });
    $tokens = (static function () use ($n): \Generator {
        for ($i = 1; $i <= $n; $i++) {
            yield ApnsDeviceToken::of(sprintf('%064x', $i));
        }
    })();

    gc_collect_cycles();
    $baseline = memory_get_usage();
    $peak = 0;
    $seen = 0;
    $report = MobilePush::connection('ios')->bulk()->to($tokens)->send(PushMessage::make('Big', 'Campaign')->data(['order' => 'A-123']));
    $report->each(static function () use (&$seen, &$peak): void {
        if (++$seen % 10_000 === 0) {
            $peak = max($peak, memory_get_usage());
        }
    });
    app(\Padosoft\MobilePush\Laravel\Logging\SinkManager::class)->flush();
    \Padosoft\MobilePush\Laravel\Logging\SinkManager::forgetExtensions();

    return ['growth' => $peak - $baseline, 'results' => $seen, 'sink' => $sink];
}

it('keeps the default path flat for 100,000 destinations: no payload is built when the level is none', function () {
    payloadVolume('none', 2_000); // warm-up: classes, the compiled-message caches, the log buffer
    $quiet = payloadVolume(null);
    $logged = payloadVolume('none');

    expect($quiet['results'])->toBe(100_000)
        ->and($logged['results'])->toBe(100_000)
        ->and($logged['sink']?->records)->toBe(100_000)
        ->and($logged['sink']?->withPayload)->toBe(0)
        ->and($logged['growth'])->toBeLessThan(8 * 1024 * 1024)
        // with the log on at level none, memory does not grow more than without any sink
        ->and($logged['growth'] - $quiet['growth'])->toBeLessThan(512 * 1024);
    fwrite(STDERR, sprintf("growth without sink %d B, with sink at none %d B\n", $quiet['growth'], $logged['growth']));
});
