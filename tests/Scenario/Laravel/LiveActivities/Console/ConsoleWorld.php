<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Console;

use Illuminate\Support\Facades\Artisan;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityPruner;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelClientFactory;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelRegistry;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\ConsoleCommands;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\MatchActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\Rider;

require_once __DIR__.'/../LiveWorld.php';

/** Same dispatcher, other default options: the way a test puts the app in simulate mode or outside production. */
final class OptionsDispatcher implements PushDispatcher
{
    public function __construct(private readonly PushDispatcher $inner, private readonly SendOptions $options) {}

    public function binding(string $connection, ?string $scope = null): BindingRef
    {
        return $this->inner->binding($connection, $scope);
    }

    public function send(BindingRef $binding, Message $message, iterable $destinations, ?SendOptions $options = null): SendReport
    {
        return $this->inner->send($binding, $message, $destinations, $options ?? $this->options);
    }

    public function driver(BindingRef $binding): Driver
    {
        return $this->inner->driver($binding);
    }

    public function defaultOptions(BindingRef $binding): SendOptions
    {
        return $this->options;
    }
}

/**
 * The Live Activities world of the module's own tests plus the console: commands registered, every run recorded,
 * and a canary: no clear token the scenario knows about may ever appear in an output.
 */
final class ConsoleWorld
{
    public const RIDER = Rider::class;

    public const CHANNEL_A = 'dHN0LXNyY2gtY2hubA==';

    public const CHANNEL_B = 'b3RoZXItY2hhbm5lbA==';

    public LiveWorld $live;

    /** @var list<string> clear tokens that must never be printed */
    public array $secrets = [];

    /** @var list<string> */
    public static array $outputs = [];

    /** @param  array<string, mixed>  $config */
    public static function install(array $config = []): self
    {
        $world = new self;
        $world->live = LiveWorld::install(['types' => ['delivery' => DeliveryActivity::class, 'match' => MatchActivity::class]] + $config);
        foreach (ConsoleCommands::all() as $command) {
            Artisan::registerCommand(app($command));
        }

        return $world;
    }

    /** The app now runs with other defaults (simulate, or live outside production, with or without authorisation). */
    public function withOptions(SendOptions $options): void
    {
        $app = app();
        $app->instance(PushDispatcher::class, new OptionsDispatcher($this->live->dispatcher, $options));
        foreach ([ChannelClientFactory::class, ChannelRegistry::class, ActivityManager::class, ActivityPruner::class] as $abstract) {
            $app->forgetInstance($abstract);
        }
    }

    /** A rider with one push-to-start token for the delivery type. */
    public function rider(int $id, string $seed = 'p1'): Rider
    {
        $rider = LiveWorld::rider($id);
        $token = LiveWorld::pushToStart($seed);
        $rider->registerPushToStartToken($this->live->binding(), 'delivery', $token);
        $this->secrets[] = $token->value();

        return $rider;
    }

    /** The app reports the update token of an activity: it becomes active. */
    public function activate(Rider $rider, string $seed = 'a1', string $client = 'C-1'): PushActivity
    {
        $token = LiveWorld::activityToken($seed);
        $this->secrets[] = $token->value();

        return $rider->reportLiveActivityToken($this->live->binding(), 'delivery', $token, new ActivityReport(clientActivityId: $client, state: ['eta' => 12]));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: int, 1: string} exit code and output
     */
    public function cli(string $command, array $params = []): array
    {
        $exit = Artisan::call($command, $params);
        $output = Artisan::output();
        self::$outputs[] = $output;

        foreach ($this->secrets as $secret) {
            expect($output)->not->toContain($secret);
        }

        return [$exit, $output];
    }

    /** @param  array<string, mixed>  $params */
    public function json(string $command, array $params = []): array
    {
        [, $output] = $this->cli($command, $params + ['--json' => true]);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }

    public function channelCreated(string $id): Response
    {
        return new Response(201, ['apns-channel-id' => $id, 'apns-request-id' => '11111111-1111-4111-8111-111111111111']);
    }

    public function channelList(string ...$ids): Response
    {
        return new Response(200, [], (string) json_encode(['channels' => $ids]));
    }

    public function advance(int $seconds): void
    {
        $this->live->kit->clock->advance($seconds);
    }

    public function activityRow(): PushActivity
    {
        $row = PushActivity::blank()->newQuery()->first();
        assert($row instanceof PushActivity);

        return $row;
    }
}
