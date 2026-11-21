<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/** The world's FakeTransport, plus a log of the pools the package asked to close (FakeTransport::close() is a no-op). */
final class PoolClosingTransport implements Transport
{
    /** @var list<string|null> */
    public array $closed = [];

    public function __construct(private readonly FakeTransport $inner) {}

    public function send(iterable $requests, ConnectionProfile $profile): Generator
    {
        return $this->inner->send($requests, $profile);
    }

    public function close(?string $poolKey = null): void
    {
        $this->closed[] = $poolKey;
        $this->inner->close($poolKey);
    }
}

/*
 * A tenant rotates its credential at runtime (MobilePush::register() with a new key), then revokes it
 * (MobilePush::unregister()). Oracles: the authorization header and pool key of every request the transport
 * received, and the pools the package closed.
 */
beforeEach(function () {
    $this->world = W::install(['connections' => ['main' => W::apns(bundle: 'com.example.default')]]);
    $this->pools = new PoolClosingTransport($this->world->transport);
    app()->instance(Transport::class, $this->pools);
    W::forgetSingletons();
    $this->send = fn (string $title) => W::all(MobilePush::scope('tenant-a')->connection('main')
        ->to(ApnsDeviceToken::of(W::apnsToken('tenant-a-phone')))->send(PushMessage::make($title)))[0];
});

it('signs with the new key after an APNs key rotation, never reuses the old token, and closes the old pool', function () {
    MobilePush::register('main', W::apns(keyId: 'KEYOLD0001', bundle: 'com.tenant-a.app'), 'tenant-a');
    ($this->send)('one');
    ($this->send)('two');

    MobilePush::register('main', W::apns(keyId: 'KEYNEW0002', bundle: 'com.tenant-a.app'), 'tenant-a');
    $afterRotation = ($this->send)('three');

    $recorded = $this->world->transport->recorded();
    [$one, $two, $three] = array_map(static fn (array $r) => $r['request'], $recorded);
    [$oldPool, , $newPool] = array_map(static fn (array $r) => $r['profile']->poolKey, $recorded);

    expect($afterRotation->status)->toBe(SendStatus::Accepted)
        // before the rotation the cached provider token is reused (one JWT for both pushes)
        ->and(W::jwtHeader($one)['kid'])->toBe('KEYOLD0001')
        ->and($two->headers['authorization'])->toBe($one->headers['authorization'])
        // after it, a token minted with the new key: the old generation's token is not read again
        ->and(W::jwtHeader($three)['kid'])->toBe('KEYNEW0002')
        ->and($three->headers['authorization'])->not->toBe($one->headers['authorization'])
        ->and($recorded[1]['profile']->poolKey)->toBe($oldPool)
        // a new pool for the new generation, and the old one closed when its driver was replaced
        ->and($newPool)->not->toBe($oldPool)
        ->and($this->pools->closed)->toContain($oldPool)
        ->and($this->pools->closed)->not->toContain($newPool);
});

it('closes the pool of a revoked credential and sends nothing through it afterwards', function () {
    MobilePush::register('main', W::apns(keyId: 'KEYOLD0001', bundle: 'com.tenant-a.app'), 'tenant-a');
    $captured = MobilePush::binding('main', 'tenant-a');
    ($this->send)('before');
    $pool = $this->world->transport->recorded()[0]['profile']->poolKey;

    MobilePush::unregister('main', 'tenant-a');
    $late = W::all(MobilePush::send($captured, PushMessage::make('after'), [ApnsDeviceToken::of(W::apnsToken('tenant-a-phone'))]))[0];

    expect($this->pools->closed)->toContain($pool)
        ->and($late->status)->toBe(SendStatus::Skipped)
        ->and($late->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($this->world->transport->sentCount())->toBe(1);
});

it('exchanges a new Google access token after an FCM service-account key rotation and closes the old pool', function () {
    $android = FcmToken::of('fake-tenant-a-android-token');
    $sendAndroid = fn () => W::all(MobilePush::scope('tenant-a')->connection('android')->to($android)->send(PushMessage::make('Hi')))[0];

    $this->world->nextBearer = 'ya29.fake-old-generation';
    MobilePush::register('android', W::fcm(keyId: 'sa-key-old'), 'tenant-a');
    $sendAndroid();
    $sendAndroid();

    $this->world->nextBearer = 'ya29.fake-new-generation';
    MobilePush::register('android', W::fcm(keyId: 'sa-key-new'), 'tenant-a');
    $result = $sendAndroid();

    $fcm = array_values(array_filter($this->world->transport->recorded(), static fn (array $r) => str_contains($r['request']->url, 'fcm.googleapis.com')));
    expect($result->status)->toBe(SendStatus::Accepted)
        ->and($this->world->issuedBearers)->toBe(['ya29.fake-old-generation', 'ya29.fake-new-generation'])
        ->and(array_map(static fn (array $r) => $r['request']->headers['authorization'], $fcm))
        ->toBe(['Bearer ya29.fake-old-generation', 'Bearer ya29.fake-old-generation', 'Bearer ya29.fake-new-generation'])
        ->and($fcm[2]['profile']->poolKey)->not->toBe($fcm[0]['profile']->poolKey)
        ->and($this->pools->closed)->toContain($fcm[0]['profile']->poolKey);
});
