<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Sending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Devices\HasPushDevices;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Logging\LogRecordFactory;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Sending\Senders;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

/** An owner model for the scenarios (no table needed: devices reference it polymorphically). */
final class Customer extends Model
{
    use HasPushDevices;

    protected $table = 'customers_fixture';

    protected $guarded = [];

    public $timestamps = false;

    public static function withId(int $id): self
    {
        $customer = new self(['id' => $id]);
        $customer->exists = true;

        return $customer;
    }
}

/**
 * Wires a Testbench application for the sending scenarios: fake clock, FakeTransport as THE transport (it also
 * answers the Google OAuth exchange), runtime-generated keys and config arrays shaped like a .env would produce.
 * Every token below is a made-up test value.
 */
final class SendingWorld
{
    public const TEAM = 'TEAM123456';

    public const BUNDLE = 'com.example.shop';

    public const PROJECT = 'example-shop';

    public const SA_EMAIL = 'push@example-shop.iam.gserviceaccount.com';

    public FakeClock $clock;

    public FakeTransport $transport;

    /** @var list<object> */
    public array $events = [];

    /** @var array<string, string> */
    private static array $pems = [];

    /** @var list<string> bearers the fake Google token endpoint handed out */
    public array $issuedBearers = [];

    public string $nextBearer = 'ya29.fake-access-token';

    /** @var \Closure(Request): ?Response provider answers; null = the default 200 */
    public \Closure $answer;

    private function __construct()
    {
        $this->answer = static fn (Request $request): ?Response => null;
    }

    /**
     * @param  array<string, mixed>  $config  extra mobile-push.* keys (dotted, relative to mobile-push)
     */
    public static function install(array $config = [], string $environment = 'production'): self
    {
        $world = new self;
        $world->clock = new FakeClock('2026-11-20T10:00:00Z');
        $world->transport = new FakeTransport;
        $world->transport->respondUsing(static function (Request $request) use ($world): ?Response {
            if (str_contains($request->url, 'oauth2.googleapis.com')) {
                $world->issuedBearers[] = $world->nextBearer;

                return new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => $world->nextBearer, 'expires_in' => 3600, 'token_type' => 'Bearer']), '1.1');
            }

            return ($world->answer)($request);
        });
        $world->transport->defaultTo(new Response(200, ['apns-id' => '11111111-2222-4333-8444-555555555555'], '{"name":"projects/example-shop/messages/0:1"}'));

        app()['env'] = $environment;
        app()->instance(ClockInterface::class, $world->clock);
        app()->instance(Transport::class, $world->transport);
        config()->set('mobile-push.mode', 'live');
        config()->set('mobile-push.connections', []);
        foreach ($config as $key => $value) {
            config()->set('mobile-push.'.$key, $value);
        }
        self::forgetSingletons();

        Event::listen('Padosoft\MobilePush\Laravel\Sending\Events\*', function (string $name, array $payload) use ($world): void {
            $world->events[] = $payload[0];
        });

        return $world;
    }

    /** Forget the sending singletons so a config change is picked up. */
    public static function forgetSingletons(): void
    {
        foreach ([
            PushManager::class, SendingSettings::class,
            ConnectionFactory::class, Senders::class,
            SinkManager::class, LogRecordFactory::class,
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
        MobilePush::clearResolvedInstances();
    }

    public function manager(): PushManager
    {
        return app(PushManager::class);
    }

    public function withDevices(): self
    {
        config()->set('mobile-push.devices.enabled', true);
        (require DevicesModule::migrationPath())->up();

        return $this;
    }

    public function register(BindingRef $binding, Destination $destination, Model $owner): PushDevice
    {
        return app(DeviceRegistrar::class)->register($binding, $destination, $owner);
    }

    // ---------------------------------------------------------------- config arrays as a .env would produce them

    /** @return array<string, mixed> */
    public static function apns(string $keyId = 'KEY1111111', string $bundle = self::BUNDLE, string $team = self::TEAM, string $environment = 'production', array $extra = []): array
    {
        return array_replace([
            'driver' => 'apns',
            'auth' => 'token',
            'team_id' => $team,
            'key_id' => $keyId,
            'key' => ['path' => null, 'base64' => base64_encode(self::ecPem($keyId))],
            'bundle_id' => $bundle,
            'environment' => $environment,
        ], $extra);
    }

    /** @return array<string, mixed> */
    public static function fcm(string $project = self::PROJECT, string $email = self::SA_EMAIL, string $keyId = 'sa-key-1', array $extra = []): array
    {
        return array_replace([
            'driver' => 'fcm',
            'auth' => 'service_account',
            'credentials' => ['path' => null, 'base64' => base64_encode(self::serviceAccountJson($project, $email, $keyId))],
            'project_id' => null,
        ], $extra);
    }

    public static function serviceAccountJson(string $project = self::PROJECT, string $email = self::SA_EMAIL, string $keyId = 'sa-key-1'): string
    {
        return (string) json_encode([
            'type' => 'service_account',
            'project_id' => $project,
            'private_key_id' => $keyId,
            'private_key' => self::rsaPem(),
            'client_email' => $email,
        ]);
    }

    public static function ecPem(string $name = 'default', string $curve = 'prime256v1'): string
    {
        return self::$pems['ec:'.$curve.':'.$name] ??= self::generate(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
    }

    public static function rsaPem(): string
    {
        return self::$pems['rsa'] ??= self::generate(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }

    /** @param  array<string, mixed>  $options */
    private static function generate(array $options): string
    {
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a test key.');
        }
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    // ---------------------------------------------------------------- oracles

    /** Requests the fake transport received for a host (the OAuth exchange is a separate host). */
    public function requestsTo(string $host): array
    {
        return array_values(array_filter($this->transport->requests(), static fn (Request $r): bool => parse_url($r->url, PHP_URL_HOST) === $host));
    }

    /** @return list<Request> provider requests (APNs + FCM), OAuth exchanges excluded */
    public function pushRequests(): array
    {
        return array_values(array_filter($this->transport->requests(), static fn (Request $r): bool => ! str_contains($r->url, 'oauth2.googleapis.com')));
    }

    /** @return list<SendResult> */
    public static function all(SendReport $report): array
    {
        return iterator_to_array($report->results(), false);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof $class));
    }

    public static function apnsToken(string $seed): string
    {
        return substr(str_repeat(hash('sha256', $seed), 2), 0, 64);
    }

    /** The header of the JWT bearer of an APNs request. */
    public static function jwtHeader(Request $request): array
    {
        $bearer = substr($request->headers['authorization'] ?? '', strlen('bearer '));
        $header = explode('.', $bearer)[0];

        return (array) json_decode((string) base64_decode(strtr($header, '-_', '+/')), true);
    }
}
