<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Testing\FakeTransportBuilder;
use Padosoft\MobilePush\Laravel\Testing\PushFake;
use Padosoft\MobilePush\Laravel\Testing\SentOptions;
use Padosoft\MobilePush\Laravel\Testing\SentPush;
use PHPUnit\Framework\AssertionFailedError;

function sentPush(PushFake $fake, string $title, string $token = 'aa', bool $queued = false, string $connection = 'ios'): void
{
    $binding = new BindingRef('default', $connection, Provider::Apns, 'com.example.app', Environment::Sandbox);
    $fake->record(new SentPush($binding, ApnsDeviceToken::of(str_repeat($token, 32)), PushMessage::make($title), new SentOptions, $queued));
}

it('matches by message class or by callback and counts exactly when told to', function (): void {
    $fake = new PushFake;
    sentPush($fake, 'a');
    sentPush($fake, 'b', 'bb');
    sentPush($fake, 'c', 'cc', queued: true);

    $fake->assertSent(PushMessage::class, 2);
    $fake->assertSent(fn (SentPush $p): bool => $p->message instanceof PushMessage && $p->message->title === 'b', 1);
    $fake->assertQueued(PushMessage::class, 1);
    $fake->assertNotSent(fn (SentPush $p): bool => $p->message instanceof PushMessage && $p->message->title === 'c');
    $fake->assertSentTimes(2);

    expect(fn () => $fake->assertSent(PushMessage::class, 3))->toThrow(AssertionFailedError::class, 'expected 3 time(s) sent, but 2 matched')
        ->and(fn () => $fake->assertNotSent(PushMessage::class))->toThrow(AssertionFailedError::class, 'must not be sent')
        ->and(fn () => $fake->assertSent('Not\\A\\Class'))->toThrow(InvalidArgumentException::class, 'not a class');
});

it('matches destinations by kind and value, and refuses a raw string', function (): void {
    $fake = new PushFake;
    sentPush($fake, 'a', 'aa');

    $fake->assertSentTo(ApnsDeviceToken::of(str_repeat('aa', 32)));
    $fake->assertSentTo([ApnsDeviceToken::of(str_repeat('bb', 32)), ApnsDeviceToken::of(str_repeat('aa', 32))], fn (SentPush $p): bool => $p->connection === 'ios');

    expect(fn () => $fake->assertSentTo(ApnsDeviceToken::of(str_repeat('bb', 32))))->toThrow(AssertionFailedError::class, 'No matching push was sent')
        ->and(fn () => $fake->assertSentTo(ApnsDeviceToken::of(str_repeat('aa', 32)), fn (): bool => false))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertSentTo('raw-token'))->toThrow(InvalidDestinationException::class)
        ->and(fn () => $fake->assertSentTo(new stdClass))->toThrow(AssertionFailedError::class, 'resolves to no push destination');
});

it('matches topics, connections and scopes', function (): void {
    $fake = new PushFake;
    $binding = new BindingRef('tenant-a', 'android', Provider::Fcm, 'proj', Environment::Production);
    $fake->record(new SentPush($binding, FcmTopic::of('sale'), PushMessage::make('x'), new SentOptions));

    $fake->assertSentToTopic('sale');
    $fake->assertSentOnConnection('android');
    $fake->assertSentInScope('tenant-a');

    expect(fn () => $fake->assertSentToTopic('other'))->toThrow(AssertionFailedError::class, 'No push was sent to the FCM topic "other"')
        ->and(fn () => $fake->assertSentInScope('default'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertSentOnConnection('ios'))->toThrow(AssertionFailedError::class);
});

it('never prints a raw device token in a failure message', function (): void {
    $fake = new PushFake;
    sentPush($fake, 'a', 'ab');

    try {
        $fake->assertNothingSent();
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->not->toContain(str_repeat('ab', 32))->toContain('apns_device_token:');
    }
    expect(print_r(new SentPush(new BindingRef('default', 'ios', Provider::Apns, 'a', Environment::Sandbox), ApnsDeviceToken::of(str_repeat('ab', 32)), PushMessage::make('x'), new SentOptions), true))
        ->not->toContain(str_repeat('ab', 32));
});

it('decodes APNs and FCM requests and redacts the authorization header', function (): void {
    $apns = new Request('r1', 'POST', 'https://api.push.apple.com/3/device/abc', ['authorization' => 'bearer secret-jwt', 'apns-topic' => 'com.example'], '{"aps":{"alert":"hi"}}');
    $fcm = new Request('r2', 'POST', 'https://fcm.googleapis.com/v1/projects/p/messages:send', ['authorization' => 'Bearer secret'], '{"message":{"token":"t","data":{"a":"b"}},"validate_only":true}');

    $a = FakeTransportBuilder::decodeApns($apns);
    $f = FakeTransportBuilder::decodeFcm($fcm);

    expect($a['headers'])->toBe(['authorization' => '[redacted]', 'apns-topic' => 'com.example'])
        ->and($a['payload'])->toBe(['aps' => ['alert' => 'hi']])
        ->and($f['validateOnly'])->toBeTrue()
        ->and($f['message']['data'])->toBe(['a' => 'b'])
        ->and(FakeTransportBuilder::decodeFcm(new Request('r3', 'POST', 'https://fcm.googleapis.com/x', [], 'not json')))->toBe(['message' => [], 'validateOnly' => false]);
});

it('builds provider-shaped error answers', function (): void {
    $wire = new FakeTransportBuilder;

    $apns = $wire->apnsError(410, 'Unregistered', 1_700_000_000_000);
    $fcm = $wire->fcmError(404, 'UNREGISTERED', 30);

    expect($apns->status)->toBe(410)
        ->and($apns->json())->toBe(['reason' => 'Unregistered', 'timestamp' => 1_700_000_000_000])
        ->and($fcm->header('retry-after'))->toBe('30')
        ->and($fcm->json()['error']['status'])->toBe('NOT_FOUND')
        ->and($fcm->json()['error']['details'][0]['errorCode'])->toBe('UNREGISTERED')
        ->and($wire->connectionLost()->evidence->value)->toBe('unknown')
        ->and($wire->notSent()->evidence->value)->toBe('not_sent');
});
