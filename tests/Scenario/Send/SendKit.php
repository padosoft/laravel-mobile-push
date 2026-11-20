<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Send;

use Padosoft\MobilePush\Core\Apns\ApnsConfig;
use Padosoft\MobilePush\Core\Apns\ApnsCredential;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendObserver;
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
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Tests\Scenario\Auth\CountingSigner;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/**
 * Wiring of the real pipeline for the send scenarios: real compilers, classifiers, planner, guards and token
 * providers over an in-memory guard/token store and a fake clock. Only the transport is fake. Keys are generated at
 * runtime; every token below is a made-up test value.
 */
final class SendKit
{
    public const TEAM = 'TEAM123456';

    public const BUNDLE = 'com.example.app';

    public const PROJECT = 'example-project';

    public readonly FakeClock $clock;

    public readonly InMemoryGuardStore $guardStore;

    public readonly InMemoryTokenStore $tokenStore;

    public readonly TokenIndex $index;

    /** @var list<int> milliseconds the sender asked to wait inline */
    public array $sleeps = [];

    /** @var array<string, CountingSigner> */
    public array $signers = [];

    /** @var array<string, array{pem: string}> */
    private static array $keys = [];

    public function __construct()
    {
        $this->clock = new FakeClock('2026-11-20T10:00:00Z');
        $this->guardStore = new InMemoryGuardStore($this->clock);
        $this->tokenStore = new InMemoryTokenStore($this->clock);
        $this->index = new TokenIndex(new KeyRing([1 => str_repeat('i', 32)]));
    }

    /**
     * @param  array<string, int|null>  $rateOverrides
     */
    public function guards(int $authThreshold = 5, ?int $ratePerMinute = null, array $rateOverrides = [], int $minSample = 200): Guards
    {
        return new Guards(
            new RateLimiter($this->guardStore, $this->clock, $ratePerMinute, 0.0, null, $rateOverrides),
            new ThrottleGate($this->guardStore, $this->clock),
            new AuthBreaker($this->guardStore, $this->clock, $authThreshold, 60),
            new DestinationQualityBreaker($this->guardStore, $this->clock, $minSample),
            $this->clock,
        );
    }

    /**
     * @param  list<SendObserver>  $observers
     */
    public function sender(Transport $transport, ?Guards $guards = null, ?RetryPolicy $policy = null, array $observers = []): Sender
    {
        return new Sender(
            $transport,
            $guards ?? $this->guards(),
            new SafeModeGuard($this->index),
            $this->clock,
            $policy ?? new RetryPolicy(jitter: 0.0),
            $observers,
            function (int $ms): void {
                $this->sleeps[] = $ms;
                $this->clock->set($this->clock->now()->modify("+{$ms} milliseconds"));
            },
        );
    }

    public static function live(mixed ...$overrides): SendOptions
    {
        return new SendOptions(...array_replace(['applicationEnvironment' => 'production', 'configuredMode' => 'live'], $overrides));
    }

    public function signer(string $keyId): CountingSigner
    {
        self::$keys[$keyId] ??= self::ecKey();

        return $this->signers[$keyId] ??= new CountingSigner(new OpenSslEs256Signer(PrivateKey::fromPem(self::$keys[$keyId]['pem']), $keyId));
    }

    public function apnsTokenProvider(string $keyId = 'KEY1111111', string $team = self::TEAM, string $scope = 'default'): ApnsTokenProvider
    {
        return new ApnsTokenProvider($this->signer($keyId), $team, $this->tokenStore, $this->clock, $scope, jitterSeconds: 0);
    }

    public function apnsCredential(string $keyId = 'KEY1111111', string $team = self::TEAM, string $scope = 'default'): ApnsCredential
    {
        return ApnsCredential::token($this->apnsTokenProvider($keyId, $team, $scope), $team, $keyId);
    }

    public static function apnsBinding(string $name = 'ios', string $scope = 'default', string $bundle = self::BUNDLE, Environment $environment = Environment::Production): BindingRef
    {
        return new BindingRef($scope, $name, Provider::Apns, $bundle, $environment);
    }

    public function apnsDriver(?ApnsCredential $credential = null, ?ApnsCredential $secondary = null, ?BindingRef $binding = null, int $window = 100): ApnsDriver
    {
        return new ApnsDriver(new ApnsConfig(
            $binding ?? self::apnsBinding(),
            $credential ?? $this->apnsCredential(),
            $secondary,
            window: $window,
        ));
    }

    public static function fcmBinding(string $name = 'android', string $project = self::PROJECT, string $scope = 'default'): BindingRef
    {
        return new BindingRef($scope, $name, Provider::Fcm, $project, Environment::Production);
    }

    /**
     * A real service-account provider whose OAuth exchange is answered by its own fake transport.
     */
    public function fcmTokenProvider(FakeTransport $oauth, string $email = 'push@example-project.iam.gserviceaccount.com', string $keyId = 'sa-key-1'): ServiceAccountTokenProvider
    {
        self::$keys['rsa'] ??= self::rsaKey();
        $credentials = ServiceAccountCredentials::fromArray([
            'type' => 'service_account',
            'project_id' => self::PROJECT,
            'private_key_id' => $keyId,
            'private_key' => self::$keys['rsa']['pem'],
            'client_email' => $email,
        ]);

        return new ServiceAccountTokenProvider($credentials, $oauth, $this->tokenStore, $this->clock);
    }

    public static function oauthAnswering(string ...$tokens): FakeTransport
    {
        $oauth = new FakeTransport;
        foreach ($tokens as $token) {
            $oauth->push(new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer']), '1.1'));
        }

        return $oauth;
    }

    public function fcmDriver(?FakeTransport $oauth = null, ?BindingRef $binding = null, bool $http2 = true, int $window = 100): FcmDriver
    {
        return new FcmDriver(new FcmConfig(
            $binding ?? self::fcmBinding(),
            $this->fcmTokenProvider($oauth ?? self::oauthAnswering('ya29.fake-access-token-one')),
            http2: $http2,
            window: $window,
        ), $this->clock);
    }

    public static function apnsToken(int $i): ApnsDeviceToken
    {
        return ApnsDeviceToken::of(str_pad(dechex($i), 64, 'a', STR_PAD_LEFT));
    }

    /** @return \Generator<int, ApnsDeviceToken> */
    public static function apnsTokens(int $n, int $from = 0): \Generator
    {
        for ($i = $from; $i < $from + $n; $i++) {
            yield self::apnsToken($i);
        }
    }

    public static function fcmToken(int $i): FcmToken
    {
        return FcmToken::of('fake-registration-token-'.$i);
    }

    /** The device token a recorded APNs request was addressed to. */
    public static function tokenOf(Request $request): string
    {
        return substr($request->url, (int) strrpos($request->url, '/') + 1);
    }

    /** @param  array<string, mixed>  $extra */
    public static function apnsReason(int $status, string $reason, array $extra = []): Response
    {
        return new Response($status, ['apns-id' => '00000000-0000-4000-8000-000000000000'], (string) json_encode(['reason' => $reason] + $extra));
    }

    /** @param  array<string, string>  $headers */
    public static function fcmError(int $status, string $rpc, ?string $code = null, array $headers = []): Response
    {
        $error = ['code' => $status, 'message' => 'fake error', 'status' => $rpc];
        if ($code !== null) {
            $error['details'] = [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $code]];
        }

        return new Response($status, $headers, (string) json_encode(['error' => $error]));
    }

    public static function fcmOk(int $n = 1): Response
    {
        return new Response(200, [], '{"name":"projects/example-project/messages/0:'.$n.'"}');
    }

    /** @return list<SendResult> */
    public static function all(SendReport $report): array
    {
        return iterator_to_array($report->results(), false);
    }

    /** @return array{pem: string} */
    private static function ecKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a test key.');
        }
        openssl_pkey_export($key, $pem);

        return ['pem' => (string) $pem];
    }

    /** @return array{pem: string} */
    private static function rsaKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a test key.');
        }
        openssl_pkey_export($key, $pem);

        return ['pem' => (string) $pem];
    }
}
