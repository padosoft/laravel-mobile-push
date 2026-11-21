<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Benchmarks;

use Padosoft\MobilePush\Core\Apns\ApnsConfig;
use Padosoft\MobilePush\Core\Apns\ApnsCredential;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Clock\SystemClock;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountCredentials;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Padosoft\MobilePush\Core\Fcm\FcmConfig;
use Padosoft\MobilePush\Core\Fcm\FcmDriver;
use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/** Transport decorator that feeds every Exchange to a Recorder (one extra generator layer, no copying). */
final class RecordingTransport implements Transport
{
    public ?Recorder $recorder = null;

    public function __construct(private readonly Transport $inner) {}

    public function send(iterable $requests, ConnectionProfile $profile): \Generator
    {
        $stream = $this->inner->send($requests, $profile);

        return (function () use ($stream) {
            foreach ($stream as $id => $exchange) {
                $this->recorder?->exchange($exchange);
                yield $id => $exchange;
            }
        })();
    }

    public function close(?string $poolKey = null): void
    {
        $this->inner->close($poolKey);
    }
}

/**
 * Wires the real pipeline (real compilers, classifiers, retry planner, guards, token providers over in-memory
 * stores, system clock) to a given Transport. The only non-production parts are the mock endpoint override and
 * throw-away keys generated at runtime.
 */
final class Kit
{
    public const TEAM = 'TEAM123456';

    public const BUNDLE = 'com.example.app';

    public const PROJECT = 'example-project';

    private static ?string $ecPem = null;

    private static ?string $rsaPem = null;

    public readonly SystemClock $clock;

    private readonly InMemoryTokenStore $tokenStore;

    private readonly InMemoryGuardStore $guardStore;

    private readonly TokenIndex $index;

    public function __construct()
    {
        $this->clock = new SystemClock;
        $this->tokenStore = new InMemoryTokenStore($this->clock);
        $this->guardStore = new InMemoryGuardStore($this->clock);
        $this->index = new TokenIndex(new KeyRing([1 => str_repeat('i', 32)]));
    }

    public function sender(Transport $transport): Sender
    {
        $guards = new Guards(
            new RateLimiter($this->guardStore, $this->clock, null, 0.0, null, []),
            new ThrottleGate($this->guardStore, $this->clock),
            new AuthBreaker($this->guardStore, $this->clock, 5, 60),
            new DestinationQualityBreaker($this->guardStore, $this->clock, 200),
            $this->clock,
        );

        return new Sender($transport, $guards, new SafeModeGuard($this->index), $this->clock, new RetryPolicy(jitter: 0.0));
    }

    /**
     * Mock endpoints are only accepted outside production for the driver config; the send options say "production"
     * so that the destination allowlist (a test-device safety net, linear in its size) is not part of what is measured.
     */
    public static function options(): SendOptions
    {
        return new SendOptions(applicationEnvironment: 'production', configuredMode: 'live');
    }

    public function apnsDriver(MockServer $server, int $window, int $connections, string $scope = 'default', int $timeoutMs = 30000): ApnsDriver
    {
        $binding = new BindingRef($scope, 'ios', Provider::Apns, self::BUNDLE, Environment::Production);
        $signer = new OpenSslEs256Signer(PrivateKey::fromPem(self::ecPem()), 'KEY1111111');
        $provider = new ApnsTokenProvider($signer, self::TEAM, $this->tokenStore, $this->clock, $scope, jitterSeconds: 0);

        return new ApnsDriver(new ApnsConfig(
            $binding,
            ApnsCredential::token($provider, self::TEAM, 'KEY1111111'),
            window: $window,
            maxConnections: $connections,
            connectTimeoutMs: 5000,
            timeoutMs: $timeoutMs,
            caBundlePath: $server->caBundlePath(),
            endpointOverride: new EndpointOverride([EndpointRole::ApnsSend->value => $server->baseUrl()], testCredentials: true),
            applicationEnvironment: 'testing',
        ));
    }

    public function fcmDriver(MockServer $server, int $window, int $connections): FcmDriver
    {
        $oauth = new FakeTransport;
        $oauth->defaultTo(new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => 'ya29.benchmark-fake-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), '1.1'));
        $credentials = ServiceAccountCredentials::fromArray([
            'type' => 'service_account',
            'project_id' => self::PROJECT,
            'private_key_id' => 'sa-key-1',
            'private_key' => self::rsaPem(),
            'client_email' => 'push@example-project.iam.gserviceaccount.com',
        ]);
        $provider = new ServiceAccountTokenProvider($credentials, $oauth, $this->tokenStore, $this->clock);

        return new FcmDriver(new FcmConfig(
            new BindingRef('default', 'android', Provider::Fcm, self::PROJECT, Environment::Production),
            $provider,
            http2: true,
            window: $window,
            maxConnections: $connections,
            connectTimeoutMs: 5000,
            timeoutMs: 30000,
            caBundlePath: $server->caBundlePath(),
            endpointOverride: new EndpointOverride([EndpointRole::FcmApi->value => $server->baseUrl()], testCredentials: true),
            applicationEnvironment: 'testing',
        ), $this->clock);
    }

    /** A message padded to roughly $bytes of JSON body (the exact size seen by the server is reported separately). */
    public static function message(int $payloadBytes): PushMessage
    {
        $m = PushMessage::make('Shipped', 'On its way');
        $base = 110; // approximate size of the compiled message without padding
        if ($payloadBytes > $base) {
            $m->data(['pad' => str_repeat('x', $payloadBytes - $base)]);
        }

        return $m;
    }

    /** @return \Generator<int, ApnsDeviceToken> the first hex digit encodes the scripted outcome (see Scenarios::mixed) */
    public static function apnsTokens(int $n, string $prefix = 'd'): \Generator
    {
        for ($i = 0; $i < $n; $i++) {
            yield ApnsDeviceToken::of($prefix.sprintf('%063x', $i));
        }
    }

    /** Token prefix encodes the scripted outcome: i%100 in 0..2 -> a (410), 3 -> b (400), 4 -> c (500), else d (200). */
    public static function mixedApnsTokens(int $n): \Generator
    {
        for ($i = 0; $i < $n; $i++) {
            $m = $i % 100;
            $prefix = $m < 3 ? 'a' : ($m === 3 ? 'b' : ($m === 4 ? 'c' : 'd'));
            yield ApnsDeviceToken::of($prefix.sprintf('%063x', $i));
        }
    }

    /** JSON body padded to exactly $bytes (when larger than the base). */
    public static function payloadBody(int $bytes): string
    {
        $base = '{"aps":{"alert":{"title":"Shipped","body":"On its way"}},"pad":""}';

        return str_replace('"pad":""', '"pad":"'.str_repeat('x', max(0, $bytes - strlen($base))).'"', $base);
    }

    /** @return \Generator<int, FcmToken> */
    public static function fcmTokens(int $n): \Generator
    {
        for ($i = 0; $i < $n; $i++) {
            yield FcmToken::of('fake-registration-token-'.$i);
        }
    }

    private static function ecPem(): string
    {
        if (self::$ecPem === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_pkey_export($key, $pem);
            self::$ecPem = (string) $pem;
        }

        return self::$ecPem;
    }

    private static function rsaPem(): string
    {
        if (self::$rsaPem === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            openssl_pkey_export($key, $pem);
            self::$rsaPem = (string) $pem;
        }

        return self::$rsaPem;
    }
}
