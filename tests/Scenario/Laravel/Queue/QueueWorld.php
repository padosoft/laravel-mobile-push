<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Queue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Queue\DeliveryRelay;
use Padosoft\MobilePush\Laravel\Queue\LedgerSweeper;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Psr\Clock\ClockInterface;

/**
 * A Testbench app for the queue stories: the real Sender (real compilers, classifiers, planner, guards) over a
 * FakeTransport, a fake clock shared by the Sender, the ledger and Carbon, runtime keys, and a small PushDispatcher
 * standing in for the manager. Every token is a made-up test value.
 */
final class QueueWorld
{
    public readonly SendKit $kit;

    public readonly FakeTransport $transport;

    public readonly RecordingObserver $results;

    public readonly WorldDispatcher $dispatcher;

    private function __construct()
    {
        $this->kit = new SendKit;
        $this->transport = new FakeTransport;
        $this->results = new RecordingObserver;
        $this->dispatcher = new WorldDispatcher($this->kit->sender($this->transport, observers: [$this->results]));
    }

    /** @param  array<string, mixed>  $config  extra mobile-push.* config */
    public static function boot(string $profile = 'light', array $config = [], bool $devices = false): self
    {
        $world = new self;
        config()->set('mobile-push.delivery.profile', $profile);
        config()->set('mobile-push.ledger.lease_seconds', 60);
        foreach ($config as $key => $value) {
            config()->set('mobile-push.'.$key, $value);
        }

        app()->instance(ClockInterface::class, $world->kit->clock);
        Carbon::setTestNow(Carbon::instance($world->kit->clock->now()));
        $ring = new KeyRing([1 => random_bytes(32)]);
        app()->instance(TokenIndex::class, new TokenIndex($ring));
        app()->instance(TokenCipher::class, new TokenCipher($ring));
        app()->forgetInstance(DatabaseLedger::class);
        app()->forgetInstance(DeviceRegistrar::class);
        app()->instance(PushDispatcher::class, $world->dispatcher);

        foreach (LedgerModule::migrationPaths() as $path) {
            (require $path)->up();
        }
        if ($devices) {
            config()->set('mobile-push.devices.enabled', true);
            (require DevicesModule::migrationPath())->up();
        }

        return $world;
    }

    /** The database queue connection with its jobs table (to look at the stored payloads). */
    public static function databaseQueue(): void
    {
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300, 'after_commit' => false]);
        config()->set('mobile-push.queue.connection', 'database');
    }

    /** @return list<array{id: int, queue: string, attempts: int, available_at: int, payload: array<string, mixed>}> */
    public static function storedJobs(): array
    {
        return DB::table('jobs')->orderBy('id')->get()->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'attempts' => (int) $row->attempts,
            'available_at' => (int) $row->available_at,
            'payload' => (array) json_decode((string) $row->payload, true),
        ])->all();
    }

    /** The job object inside a stored payload (decrypting it when it is encrypted). */
    public static function jobIn(array $payload): object
    {
        $command = $payload['data']['command'];
        if (! str_starts_with($command, 'O:')) {
            $command = app('encrypter')->decrypt($command);
        }

        return unserialize($command);
    }

    /** A distinct APNs device token per number (offset by one from SendKit::apnsToken(), which is distinct as well). */
    public static function token(int $i): ApnsDeviceToken
    {
        return ApnsDeviceToken::of(sprintf('%064x', $i + 1));
    }

    /** @return \Generator<int, ApnsDeviceToken> */
    public static function tokens(int $n, int $from = 0): \Generator
    {
        for ($i = $from; $i < $from + $n; $i++) {
            yield self::token($i);
        }
    }

    public function advance(int $seconds): void
    {
        $this->kit->clock->advance($seconds);
        Carbon::setTestNow(Carbon::instance($this->kit->clock->now()));
    }

    public function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    public function relay(): DeliveryRelay
    {
        return app(DeliveryRelay::class);
    }

    public function sweeper(): LedgerSweeper
    {
        return app(LedgerSweeper::class);
    }

    public function run(SendPushJob|RelayDeliveriesJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    /** APNs requests per device token, counted at the transport. @return array<string, int> */
    public function apnsRequestsPerToken(): array
    {
        $counts = [];
        foreach ($this->transport->requests() as $request) {
            $token = SendKit::tokenOf($request);
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }

    /** FCM requests per registration token, read from the request bodies. @return array<string, int> */
    public function fcmRequestsPerToken(): array
    {
        $counts = [];
        foreach ($this->transport->requests() as $request) {
            $token = self::fcmTokenOf($request);
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }

    public static function fcmTokenOf(Request $request): string
    {
        $body = json_decode($request->body, true);

        return (string) ($body['message']['token'] ?? '');
    }

    /** @return array<string, int> state => rows */
    public static function deliveryStates(?string $effectId = null): array
    {
        $query = DB::table('mobile_push_deliveries');
        if ($effectId !== null) {
            $query->where('effect_id', $effectId);
        }

        return $query->groupBy('state')->selectRaw('state, count(*) as n')->pluck('n', 'state')->map(static fn ($n): int => (int) $n)->all();
    }
}

/**
 * Stands in for the push manager: one driver per (scope, connection name), the real Sender, and the binding rule
 * of the contract (a binding whose identity no longer matches the configured one is Skipped(BindingMismatch)).
 */
final class WorldDispatcher implements PushDispatcher
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    public ?int $window = null;

    public function __construct(public readonly Sender $sender) {}

    public function add(Driver $driver): self
    {
        $this->drivers[$driver->binding()->scope.'|'.$driver->binding()->name] = $driver;

        return $this;
    }

    public function binding(string $connection, ?string $scope = null): BindingRef
    {
        $driver = $this->drivers[($scope ?? 'default').'|'.$connection] ?? throw new UnknownConnectionException('No connection '.$connection.'.');

        return $driver->binding();
    }

    public function send(BindingRef $binding, Message $message, iterable $destinations, ?SendOptions $options = null): SendReport
    {
        $driver = $this->drivers[$binding->scope.'|'.$binding->name] ?? null;
        if ($driver === null || ! $driver->binding()->sameIdentityAs($binding)) {
            return new SendReport(self::mismatch($destinations));
        }

        return $this->sender->send($driver, $message, $destinations, $options ?? $this->defaultOptions($binding));
    }

    public function driver(BindingRef $binding): Driver
    {
        return $this->drivers[$binding->scope.'|'.$binding->name] ?? throw new UnknownConnectionException('No connection '.$binding->name.'.');
    }

    public function defaultOptions(BindingRef $binding): SendOptions
    {
        return SendKit::live(window: $this->window);
    }

    /**
     * @param  iterable<Destination|SendItem>  $destinations
     * @return \Generator<int, SendResult>
     */
    private static function mismatch(iterable $destinations): \Generator
    {
        foreach ($destinations as $value) {
            $item = SendItem::of($value);
            yield new SendResult($item->destination, SendStatus::Skipped, Reason::of(ReasonCode::BindingMismatch), deliveryId: $item->deliveryId);
        }
    }
}
