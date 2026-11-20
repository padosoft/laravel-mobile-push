<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\CapturingHandler;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Psr\Clock\ClockInterface;

it('keeps results and request count unchanged when an event listener throws and the log table is broken', function () {
    $world = W::install([
        'connections' => ['ios' => W::apns()],
        'log.sinks' => ['database'],          // the mobile_push_logs table was never migrated
        'log.database.mode' => 'all',
        'events.per_result' => 'always',
    ]);
    Event::listen(PushResultProduced::class, function (): void {
        throw new RuntimeException('listener bug');
    });
    $tokens = [W::apnsToken('one'), W::apnsToken('two'), W::apnsToken('three')];

    $report = MobilePush::toTokens(Platform::Ios, $tokens)->send(PushMessage::make('Hi'));
    $counts = $report->counts();
    app(SinkManager::class)->flush();

    expect($counts[SendStatus::Accepted->value])->toBe(3)
        ->and($report->count())->toBe(3)
        ->and($world->transport->sentCount())->toBe(3)
        ->and(app(SinkManager::class)->stats()['sinks']['database']['failed'])->toBe(3);
});

it('never lets a device token or a bearer reach an event, the log or an exception message', function () {
    $token = 'CANARY-DEVICE-TOKEN-'.bin2hex(random_bytes(10));
    $gone = 'CANARY-GONE-TOKEN-'.bin2hex(random_bytes(10));
    $world = W::install([
        'connections' => ['android' => W::fcm()],
        'log.sinks' => ['log'],
        'log.channel' => 'mp_canary',
        'events.per_result' => 'always',
    ]);
    config()->set('logging.channels.mp_canary', ['driver' => 'monolog', 'handler' => CapturingHandler::class, 'level' => 'debug']);
    CapturingHandler::$records = [];
    $world->nextBearer = 'ya29.CANARY-BEARER-'.bin2hex(random_bytes(10));
    $world->answer = fn (Request $r): ?Response => str_contains($r->body, $gone)
        ? new Response(404, [], (string) json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => "Requested entity was not found: {$gone}", 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]))
        : null;

    $report = MobilePush::to([FcmToken::of($token), FcmToken::of($gone)])->send(PushMessage::make('Hi')->data(['note' => 'plain']));
    $results = W::all($report);
    app(SinkManager::class)->flush();

    $messages = [];
    foreach ([
        fn () => $report->throw(),
        fn () => MobilePush::toToken(Platform::Ios, $token)->send(PushMessage::make('x')),
        fn () => MobilePush::connection('android')->to($token)->send(PushMessage::make('x')),
    ] as $attempt) {
        try {
            $attempt();
        } catch (PushException $e) {
            $messages[] = $e->getMessage();
        }
    }

    $surfaces = [
        'events (serialized)' => serialize($world->events),
        'events (json)' => (string) json_encode($world->events),
        'events (print_r)' => call_user_func('print_r', $world->events, true),
        'log' => CapturingHandler::dump(),
        'exceptions' => implode("\n", $messages),
    ];

    expect($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($world->issuedBearers)->toBe([$world->nextBearer])
        ->and(count($world->events))->toBeGreaterThanOrEqual(3)
        ->and(count(CapturingHandler::$records))->toBe(2)
        ->and($messages)->toHaveCount(3);
    foreach ($surfaces as $surface => $text) {
        foreach (['token' => $token, 'unregistered token' => $gone, 'bearer' => $world->nextBearer] as $what => $canary) {
            expect(str_contains($text, $canary))->toBeFalse("{$what} leaked into {$surface}");
        }
    }
});

dataset('bad credentials', [
    'an expired certificate' => [fn () => ['auth' => 'certificate', 'certificate' => ['inline' => expiredCertificate()], 'key_id' => null, 'key' => null], 'expired', 'Create a new certificate'],
    'a key on the wrong curve' => [fn () => ['key' => ['base64' => base64_encode(W::ecPem('p384', 'secp384r1'))]], 'EC P-256', 'Download the .p8 key again'],
    'a secondary key of another team' => [fn () => ['secondary' => ['team_id' => 'OTHERTEAM1', 'key_id' => 'KEY9999999', 'key' => ['base64' => base64_encode(W::ecPem('other-team'))]]], 'different teams', 'use a secondary from the same team'],
    'a missing key file' => [fn () => ['key' => ['path' => '/nonexistent/AuthKey.p8']], 'could not be read', 'not readable'],
]);

it('fails early and safely on bad credentials, with the remedy and without key material', function (Closure $override, string $problem, string $remedy) {
    $world = W::install();
    config()->set('mobile-push.connections', ['ios' => W::apns(extra: $override())]);   // after install: may move the clock
    W::forgetSingletons();

    try {
        MobilePush::toToken(Platform::Ios, W::apnsToken('x'))->send(PushMessage::make('Hi'));
        $this->fail('A ConfigurationException was expected.');
    } catch (ConfigurationException $e) {
        $message = $e->getMessage();
    }

    expect($world->transport->sentCount())->toBe(0)
        ->and($message)->toContain($problem)
        ->and($message)->toContain($remedy)
        ->and($message)->toContain('mobile-push.connections.ios')
        ->and($message)->not->toContain('BEGIN')
        ->and($message)->not->toContain('PRIVATE')
        ->and($message)->not->toContain(substr(base64_encode(W::ecPem('p384', 'secp384r1')), 40, 24))
        ->and($message)->not->toContain(substr(base64_encode(W::ecPem('other-team')), 40, 24));
})->with('bad credentials');

/** A self-signed certificate (with its key) that expired: the fake clock is moved past its notAfter. */
function expiredCertificate(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'Apple Push Services: '.W::BUNDLE, 'organizationalUnitName' => W::TEAM], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    app(ClockInterface::class)->set(new DateTimeImmutable('+3 days'));

    return $certPem.$keyPem;
}
