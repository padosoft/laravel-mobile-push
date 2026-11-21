<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityPruner;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityRegistry;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelClientFactory;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelRegistry;
use Padosoft\MobilePush\Laravel\LiveActivities\HasLiveActivities;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesConfig;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;
use Padosoft\MobilePush\Laravel\LiveActivities\TypeRegistry;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Psr\Clock\ClockInterface;

/** The food-delivery activity of the test app: Swift `DeliveryAttributes`, schema version 2. */
final class DeliveryActivity implements LiveActivityType
{
    public function swiftName(): string
    {
        return 'DeliveryAttributes';
    }

    public function version(): int
    {
        return 2;
    }
}

/** The football match activity, followed through a broadcast channel. */
final class MatchActivity implements LiveActivityType
{
    public function swiftName(): string
    {
        return 'MatchAttributes';
    }

    public function version(): int
    {
        return 1;
    }
}

final class Rider extends Model
{
    use HasLiveActivities;

    protected $table = 'riders_fixture';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * A minimal PushDispatcher over the REAL pipeline: Sender + ApnsDriver + ApnsCompiler + classifier, with only the
 * transport fake. One binding name ("ios") per scope; the identity follows the scope.
 */
final class LiveDispatcher implements PushDispatcher
{
    /** @var array<string, ApnsDriver> */
    private array $drivers = [];

    /** The bundle id the connection currently points to (change it to simulate a reconfigured connection). */
    public string $bundle = SendKit::BUNDLE;

    public function __construct(public readonly SendKit $kit, public readonly Sender $sender, public string $environment = 'production') {}

    /** @var list<string> the push connections this dispatcher knows; any other name is an unknown connection */
    public array $connections = ['ios'];

    public function binding(string $connection, ?string $scope = null): BindingRef
    {
        if (! in_array($connection, $this->connections, true)) {
            throw new UnknownConnectionException("No push connection named \"{$connection}\".");
        }

        return SendKit::apnsBinding($connection, $scope ?? 'default', $this->bundle);
    }

    /** Forces the options of every send, whatever the caller passed (a pipeline that disagrees with defaultOptions()). */
    public ?SendOptions $forceSendOptions = null;

    public function send(BindingRef $binding, Message $message, iterable $destinations, ?SendOptions $options = null): SendReport
    {
        return $this->sender->send($this->driver($binding), $message, $destinations, $this->forceSendOptions ?? $options ?? $this->defaultOptions($binding));
    }

    public function driver(BindingRef $binding): Driver
    {
        return $this->drivers[$binding->fingerprint()] ??= $this->kit->apnsDriver(binding: $binding);
    }

    /** Replace the default options (simulate mode, staging with an allowlist, ...); null = live in production. */
    public ?SendOptions $options = null;

    public function defaultOptions(BindingRef $binding): SendOptions
    {
        return $this->options ?? SendKit::live(applicationEnvironment: $this->environment);
    }
}

/** Wires the Live Activities module of a Testbench app to the real pipeline, a fake transport and a fake clock. */
final class LiveWorld
{
    public SendKit $kit;

    public FakeTransport $transport;

    public LiveDispatcher $dispatcher;

    /** @var list<Response|TransportFailure> answers for channel-management requests, in order */
    public array $channelAnswers = [];

    /** @var list<Response|TransportFailure> answers for pushes (device and broadcast), in order; default 200 */
    public array $pushAnswers = [];

    /** @param  array<string, mixed>  $config */
    public static function install(array $config = [], ?RetryPolicy $policy = null): self
    {
        $world = new self;
        $world->kit = new SendKit;
        $world->transport = new FakeTransport;
        $world->transport->respondUsing(function (Request $request) use ($world): Response|TransportFailure|null {
            $management = str_contains($request->url, 'api-manage-broadcast');
            $queue = $management ? 'channelAnswers' : 'pushAnswers';

            return array_shift($world->{$queue}) ?? ($management ? new Response(200, [], '{"channels":[]}') : new Response(200, ['apns-id' => $request->headers['apns-id'] ?? 'x']));
        });

        $sender = $world->kit->sender($world->transport, policy: $policy ?? new RetryPolicy(jitter: 0.0));
        $world->dispatcher = new LiveDispatcher($world->kit, $sender);

        $app = app();
        $app->instance(ClockInterface::class, $world->kit->clock);
        $app->instance(PushDispatcher::class, $world->dispatcher);
        $app->instance(Transport::class, $world->transport);
        // The same HMAC index as the pipeline's SafeModeGuard, so live.only_to allowlists computed here match.
        $app->instance(TokenIndex::class, $world->kit->index);
        $app->instance(TokenCipher::class, new TokenCipher(new KeyRing([1 => random_bytes(32)])));

        config()->set('mobile-push.live_activities.enabled', true);
        config()->set('mobile-push.live_activities.binding', 'ios');
        foreach ($config as $key => $value) {
            config()->set('mobile-push.live_activities.'.$key, $value);
        }
        foreach ([LiveActivitiesConfig::class, Rows::class, TypeRegistry::class, ActivityRegistry::class, ChannelClientFactory::class, ChannelRegistry::class, ActivityManager::class, ActivityPruner::class] as $abstract) {
            $app->forgetInstance($abstract);
        }

        foreach (LiveActivitiesModule::migrationPaths() as $path) {
            (require $path)->up();
        }

        return $world;
    }

    public function manager(): ActivityManager
    {
        return app(ActivityManager::class);
    }

    public function registry(): ActivityRegistry
    {
        return app(ActivityRegistry::class);
    }

    public function channels(): ChannelRegistry
    {
        return app(ChannelRegistry::class);
    }

    public function binding(string $scope = 'default'): BindingRef
    {
        return SendKit::apnsBinding('ios', $scope);
    }

    public static function rider(int $id): Rider
    {
        $rider = new Rider(['id' => $id]);
        $rider->exists = true;

        return $rider;
    }

    public static function pushToStart(string $seed): ApnsPushToStartToken
    {
        return ApnsPushToStartToken::of(str_repeat(bin2hex(substr($seed.'pts', 0, 4)), 16));
    }

    public static function activityToken(string $seed): ApnsLiveActivityToken
    {
        return ApnsLiveActivityToken::of(str_repeat(bin2hex(substr($seed.'lat', 0, 4)), 16));
    }

    /** @return list<Request> pushes only (device and broadcast routes), in order */
    public function pushes(): array
    {
        return array_values(array_filter($this->transport->requests(), static fn (Request $r): bool => ! str_contains($r->url, 'api-manage-broadcast')));
    }

    /** @return list<Request> */
    public function managementRequests(): array
    {
        return array_values(array_filter($this->transport->requests(), static fn (Request $r): bool => str_contains($r->url, 'api-manage-broadcast')));
    }

    /** @return array<string, mixed> the `aps` dictionary of a request */
    public static function aps(Request $request): array
    {
        $json = json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($json) && is_array($json['aps'] ?? null));

        /** @var array<string, mixed> */
        return $json['aps'];
    }

    public function second(): int
    {
        return $this->kit->clock->now()->getTimestamp();
    }
}
