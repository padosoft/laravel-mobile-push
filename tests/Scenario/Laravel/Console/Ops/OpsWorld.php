<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Console\Ops\OpsCommands;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Logging\LoggingModule;
use Padosoft\MobilePush\Laravel\Queue\DeliveryRelay;
use Padosoft\MobilePush\Laravel\Queue\LedgerSweeper;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;

require_once __DIR__.'/../../Sending/SendingWorld.php';

/**
 * A Testbench application for the operational commands: the real push manager and sender over a FakeTransport (the
 * oracle), runtime-generated keys, a fake clock shared by the ledger and Carbon, SQLite in memory with the ledger,
 * device and (optionally) log tables. The commands are registered here, so the scenarios do not depend on the console
 * module of another slice. Every command output is collected: a clear token must appear in none of them.
 */
final class OpsWorld
{
    /** @var list<string> */
    public array $outputs = [];

    /** @var list<string> clear tokens that must never be printed */
    public array $secrets = [];

    private function __construct(public readonly SendingWorld $w) {}

    /** @param  array<string, mixed>  $config  mobile-push.* keys (dotted)  */
    public static function boot(array $config = [], string $profile = 'durable', bool $logs = false): self
    {
        Queue::fake();
        $w = SendingWorld::install(array_replace([
            'connections' => ['ios' => SendingWorld::apns(), 'android' => SendingWorld::fcm()],
            'delivery.profile' => $profile,
            'ledger.lease_seconds' => 60,
            'cache.store' => 'array',
        ], $config));
        Carbon::setTestNow(Carbon::instance($w->clock->now()));

        foreach ([DatabaseLedger::class, DeviceRegistrar::class, DeliveryRelay::class, LedgerSweeper::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
        foreach (LedgerModule::migrationPaths() as $path) {
            (require $path)->up();
        }
        $w->withDevices();
        if ($logs) {
            config()->set('mobile-push.log.sinks', ['database']);
            (require LoggingModule::migrationPath())->up();
        }

        $world = new self($w);
        foreach (OpsCommands::all() as $class) {
            Artisan::registerCommand(app($class));
        }

        return $world;
    }

    /** Run a command non-interactively and keep its output. @return array{0: int, 1: string} */
    public function call(string $command, array $arguments = []): array
    {
        $exit = Artisan::call($command, $arguments + ['--no-interaction' => true]);
        $output = Artisan::output();
        $this->outputs[] = $output;

        return [$exit, $output];
    }

    /** @return array<string, mixed> */
    public function json(string $command, array $arguments = []): array
    {
        [, $output] = $this->call($command, $arguments + ['--json' => true]);
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('The command did not print valid JSON: '.$output);
        }

        return $decoded;
    }

    public function advance(int $seconds): void
    {
        $this->w->clock->advance($seconds);
        Carbon::setTestNow(Carbon::instance($this->w->clock->now()));
    }

    public function manager(): PushManager
    {
        return app(PushManager::class);
    }

    public function binding(string $connection = 'ios', ?string $scope = null): BindingRef
    {
        return $this->manager()->binding($connection, $scope);
    }

    /** Queue a durable effect (nothing is dispatched: the queue is faked) and return its id. @param  iterable<Destination>  $destinations */
    public function queue(iterable $destinations, string $key, ?PushMessage $message = null, string $connection = 'ios', ?string $scope = null): string
    {
        $binding = $this->binding($connection, $scope);
        $options = $this->manager()->defaultOptions($binding)->withEffectKey($key);

        return app(QueuedSender::class)->queue($binding, $message ?? PushMessage::make('Order', 'Shipped')->ttl(7200), $destinations, $options, new QueueOptions);
    }

    /** Remember a clear secret so assertClean() can look for it in every output. */
    public function secret(string ...$values): void
    {
        array_push($this->secrets, ...$values);
    }

    public function assertClean(): void
    {
        $all = implode("\n", $this->outputs);
        foreach ($this->secrets as $secret) {
            expect($all)->not->toContain($secret);
        }
    }

    /** @return array<string, int> */
    public static function deliveryStates(): array
    {
        return DB::table('mobile_push_deliveries')->groupBy('state')->selectRaw('state, count(*) as n')->pluck('n', 'state')->map(static fn ($n): int => (int) $n)->all();
    }

    public static function device(int|string $id): PushDevice
    {
        return PushDevice::query()->findOrFail($id);
    }
}
