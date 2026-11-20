<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Devices;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Devices\HasPushDevices;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

/** Test fixture owner: any Eloquent model can own devices. */
final class TestUser extends Model
{
    use HasPushDevices;

    protected $table = 'users_fixture';

    protected $guarded = [];

    public $timestamps = false;
}

/** Wires keys, clock and the device table into a Testbench app. */
final class DeviceWorld
{
    public FakeClock $clock;

    private function __construct(public readonly FoundationTestCase $test) {}

    public static function install(FoundationTestCase $test, int $keyVersions = 1): self
    {
        $world = new self($test);
        $world->clock = new FakeClock('2026-11-20T10:00:00Z');
        $app = $test->app;
        $app->instance(ClockInterface::class, $world->clock);
        $world->useKeys($keyVersions);

        config()->set('mobile-push.devices.enabled', true);
        (require DevicesModule::migrationPath())->up();

        return $world;
    }

    /** Replace the key ring (runtime-generated keys); versions 1..$versions, newest current. */
    public function useKeys(int $versions, ?array $existing = null): KeyRing
    {
        $keys = $existing ?? [];
        for ($v = count($keys) + 1; $v <= $versions; $v++) {
            $keys[$v] = random_bytes(32);
        }
        $ring = new KeyRing($keys);
        $this->test->app->instance(TokenIndex::class, new TokenIndex($ring));
        $this->test->app->instance(TokenCipher::class, new TokenCipher($ring));
        // The singletons capture the old ring: forget them.
        foreach ([\Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar::class] as $abstract) {
            $this->test->app->forgetInstance($abstract);
        }
        $this->keys = $keys;

        return $ring;
    }

    /** @var array<int, string> */
    public array $keys = [];

    public static function apnsBinding(string $scope = 'default', Environment $env = Environment::Production, string $app = 'com.example.app'): BindingRef
    {
        return new BindingRef($scope, 'main', Provider::Apns, $app, $env);
    }

    public static function fcmBinding(string $scope = 'default'): BindingRef
    {
        return new BindingRef($scope, 'android', Provider::Fcm, 'example-project', Environment::Production);
    }

    public static function apnsToken(string $seed = 'a'): ApnsDeviceToken
    {
        return ApnsDeviceToken::of(str_repeat(bin2hex($seed.'x'), 32));
    }

    public static function fcmToken(string $seed = 'a'): FcmToken
    {
        return FcmToken::of('fcm-'.$seed.'-'.str_repeat('Zq9', 20));
    }

    public static function user(int $id): TestUser
    {
        $user = new TestUser(['id' => $id]);
        $user->exists = true;

        return $user;
    }
}
