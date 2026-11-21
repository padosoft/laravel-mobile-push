<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\Audience;
use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignManager;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignsModule;
use Padosoft\MobilePush\Laravel\Campaigns\PlanCampaignJob;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../Queue/QueueWorld.php';

/**
 * A Testbench app for the campaign stories, on top of QueueWorld: the durable profile, the ledger, the device
 * registry and the campaigns table on SQLite in memory; the real Sender (compilers, classifiers, planner, guards)
 * over a FakeTransport; a fake clock shared by everything; Queue::fake() with a FIFO drain that really executes the
 * queued planner and relay jobs. Every token is a made-up test value.
 */
final class CampaignWorld
{
    public readonly QueueWorld $queue;

    public readonly CampaignDispatcher $dispatcher;

    /** One byte per seeded token number: how many APNs requests reached the transport for it. */
    private string $hits = '';

    /** @var \SplObjectStorage<object, true> jobs already executed by drain() */
    private \SplObjectStorage $ran;

    /** @var (\Closure(Request): mixed)|null */
    private ?\Closure $answer = null;

    private int $peak = 0;

    /** @var list<array{pattern: string, left: int}> armed kill points (see killOn()) */
    private static array $kills = [];

    /** Set once a kill point fired: the process is dead, every later statement of that job fails too. */
    private static bool $dead = false;

    /**
     * @param  array<string, mixed>  $config  extra mobile-push.* config
     * @param  (\Closure(SendKit): Guards)|null  $guards
     */
    private function __construct(array $config, ?\Closure $guards)
    {
        Queue::fake();
        $this->queue = QueueWorld::boot('durable', $config + ['campaigns.enabled' => true], devices: true);
        $kit = $this->queue->kit;
        $this->dispatcher = new CampaignDispatcher($kit->sender($this->queue->transport, $guards === null ? null : $guards($kit)));
        $this->dispatcher->add($kit->apnsDriver());
        $this->dispatcher->add($kit->apnsDriver(binding: SendKit::apnsBinding(scope: 'tenant-b')));
        $this->dispatcher->add($kit->fcmDriver());
        app()->instance(PushDispatcher::class, $this->dispatcher);
        (require CampaignsModule::migrationPath())->up();
        $this->ran = new \SplObjectStorage;
        self::$kills = [];
        self::$dead = false;
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (self::$dead) {
                throw new WorkerKilled('The worker is dead: nothing after the kill point runs.');
            }
            $sql = str_replace('`', '"', $sql);   // the patterns are written with ANSI quotes; MySQL quotes with backticks
            foreach (self::$kills as $i => $kill) {
                if (preg_match($kill['pattern'], $sql) === 1 && --self::$kills[$i]['left'] === 0) {
                    unset(self::$kills[$i]);
                    self::$dead = true;

                    throw new WorkerKilled('The worker was killed at: '.$kill['pattern']);
                }
            }
        });

        $this->queue->transport->keepLast(50);
        $this->queue->transport->respondUsing(function (Request $request): mixed {
            $this->count($request);

            return $this->answer === null ? null : ($this->answer)($request);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  (\Closure(SendKit): Guards)|null  $guards  the real Guards to send with (default: SendKit's)
     */
    public static function boot(array $config = [], ?\Closure $guards = null): self
    {
        return new self($config, $guards);
    }

    public function campaigns(): CampaignManager
    {
        return app(CampaignManager::class);
    }

    public static function message(): PushMessage
    {
        return PushMessage::make('Black Friday', 'Everything -40% today')->ttl(86_400);
    }

    public function dispatch(Audience $audience, string $scope = 'default', ?int $rate = null, string $via = 'ios', ?PushMessage $message = null, string $name = 'black-friday'): Campaign
    {
        return $this->campaigns()->campaign($message ?? self::message())
            ->name($name)->via($via)->scope($scope)->audience($audience)->rate($rate)->dispatch();
    }

    /** How the transport answers (null = 200 OK). */
    public function answer(?\Closure $answer): void
    {
        $this->answer = $answer;
    }

    /**
     * Kill the worker (throw WorkerKilled) right before the `$nth` SQL statement matching `$pattern` runs: the
     * statement does not execute, and nothing after it in that job reaches the database either (a dead process
     * cannot retry a write: code that catches the first error and tries again fails as well).
     */
    public static function killOn(string $pattern, int $nth = 1): void
    {
        self::$kills[] = ['pattern' => $pattern, 'left' => $nth];
    }

    /** Run the next queued job and expect it to die at an armed kill point. Returns the job (to rerun it). */
    public function runUntilKilled(): object
    {
        while (($job = $this->nextJob()) !== null) {
            $this->ran[$job] = true;
            try {
                app()->call([$job, 'handle']);
            } catch (WorkerKilled) {
                self::$dead = false;

                return $job;
            }
            if (self::$dead) {
                self::$dead = false;   // the job swallowed the kill: it died all the same

                return $job;
            }
        }

        throw new \LogicException('No job reached the kill point.');
    }

    // ------------------------------------------------------------------ devices

    /**
     * Insert `$n` active APNs devices of the default binding efficiently (encrypted tokens, HMAC index), numbered from
     * `$from`; the owner of device number i is user i.
     */
    public static function seedDevices(int $n, int $from = 0, ?BindingRef $binding = null): void
    {
        $binding ??= SendKit::apnsBinding();
        $cipher = app(TokenCipher::class);
        $index = app(TokenIndex::class);
        $now = now()->format('Y-m-d H:i:s.v');
        $rows = [];
        for ($i = $from; $i < $from + $n; $i++) {
            $token = QueueWorld::token($i);
            $rows[] = [
                'scope' => $binding->scope,
                'owner_type' => 'user',
                'owner_id' => (string) $i,
                'binding_name' => $binding->name,
                'binding_identity' => $binding->identity(),
                'provider' => $binding->provider->value,
                'platform' => 'ios',
                'app_id' => $binding->appId,
                'environment' => $binding->environment->value,
                'kind' => DestinationKind::ApnsDeviceToken->value,
                'token' => $cipher->encrypt($token->value(), $binding->scope, DestinationKind::ApnsDeviceToken->value),
                'token_index' => $index->index($token, $binding->scope),
                'generation' => 1,
                'state' => 'active',
                'registered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) === 500) {
                DB::table('mobile_push_devices')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('mobile_push_devices')->insert($rows);
        }
    }

    // ------------------------------------------------------------------ queue

    /**
     * Run every queued planner and relay job (and the jobs they queue) in FIFO order until none is left.
     * `$stopAfter` = stop after that many jobs (to look at an intermediate state). Returns the jobs run.
     *
     * @param  (\Closure(object): void)|null  $before  called before each job (checkpoints)
     */
    public function drain(?int $stopAfter = null, ?\Closure $before = null): int
    {
        $run = 0;
        while (($job = $this->nextJob()) !== null) {
            if ($stopAfter !== null && $run >= $stopAfter) {
                break;
            }
            $this->ran[$job] = true;
            if ($before !== null) {
                $before($job);
            }
            app()->call([$job, 'handle']);
            $run++;
            $this->peak = max($this->peak, memory_get_usage());
        }

        return $run;
    }

    /** Pretend the queue handed this job out (a duplicate delivery or a retry after the worker died). */
    public function rerun(object $job): void
    {
        app()->call([$job, 'handle']);
    }

    /** Queued jobs not yet run by drain(). @return list<object> */
    public function pending(?string $class = null): array
    {
        $pending = [];
        foreach ([PlanCampaignJob::class, RelayDeliveriesJob::class] as $type) {
            if ($class !== null && $type !== $class) {
                continue;
            }
            foreach (Queue::pushed($type) as $job) {
                if (! $this->ran->contains($job)) {
                    $pending[] = $job;
                }
            }
        }

        return $pending;
    }

    /** Mark a queued job as lost (its worker died before running it). */
    public function lose(object $job): void
    {
        $this->ran[$job] = true;
    }

    public function peakMemory(): int
    {
        return $this->peak;
    }

    private function nextJob(): ?object
    {
        // Planner slices first, then relays: the order a busy queue would most likely run them in does not matter
        // for correctness; this one keeps the test fast.
        return $this->pending(PlanCampaignJob::class)[0] ?? $this->pending(RelayDeliveriesJob::class)[0] ?? null;
    }

    // ------------------------------------------------------------------ oracles

    private function count(Request $request): void
    {
        if (! str_contains($request->url, '/3/device/')) {
            return;   // only APNs device requests carry a seeded token number
        }
        $number = (int) hexdec(substr(SendKit::tokenOf($request), -12)) - 1;
        if ($number < 0) {
            return;
        }
        if (strlen($this->hits) <= $number) {
            $this->hits .= str_repeat("\0", $number + 1 - strlen($this->hits));
        }
        $this->hits[$number] = chr(min(255, ord($this->hits[$number]) + 1));
    }

    /** APNs requests counted at the transport for token number i. */
    public function requestsFor(int $i): int
    {
        return isset($this->hits[$i]) ? ord($this->hits[$i]) : 0;
    }

    /**
     * Request counts over token numbers [from, from+n): how many tokens got 0, 1, or more requests.
     *
     * @return array{0: int, 1: int, many: int}
     */
    public function requestHistogram(int $n, int $from = 0): array
    {
        $histogram = [0 => 0, 1 => 0, 'many' => 0];
        for ($i = $from; $i < $from + $n; $i++) {
            $c = $this->requestsFor($i);
            $c === 0 ? $histogram[0]++ : ($c === 1 ? $histogram[1]++ : $histogram['many']++);
        }

        return $histogram;
    }

    /** Requests sent to tokens outside [from, from+n). */
    public function requestsOutside(int $n, int $from = 0): int
    {
        $outside = 0;
        $length = strlen($this->hits);
        for ($i = 0; $i < $length; $i++) {
            if ($i < $from || $i >= $from + $n) {
                $outside += ord($this->hits[$i]);
            }
        }

        return $outside;
    }

    /** Deliveries of a campaign by state, read straight from the ledger table. @return array<string, int> */
    public static function states(string $campaignId): array
    {
        return DB::table('mobile_push_deliveries')->where('campaign_id', $campaignId)
            ->groupBy('state')->selectRaw('state, count(*) as n')->pluck('n', 'state')->map(static fn ($n): int => (int) $n)->all();
    }

    public static function deliveries(string $campaignId): int
    {
        return DB::table('mobile_push_deliveries')->where('campaign_id', $campaignId)->count();
    }

    /** Distinct registry devices with a delivery, and the number of delivery rows, for a campaign. @return array{devices: int, rows: int} */
    public static function deviceCoverage(string $campaignId): array
    {
        return [
            'devices' => (int) DB::table('mobile_push_deliveries')->where('campaign_id', $campaignId)->distinct()->count('device_id'),
            'rows' => self::deliveries($campaignId),
        ];
    }

    public static function row(string $campaignId): object
    {
        return DB::table('mobile_push_campaigns')->where('id', $campaignId)->first();
    }

    public static function token(int $i): ApnsDeviceToken
    {
        return QueueWorld::token($i);
    }
}

/**
 * Stands in for the push manager: one driver per (scope, connection), the real Sender, the binding rule of the
 * contract, and host gates (consent) added to the default options, evaluated right before the network.
 */
final class CampaignDispatcher implements PushDispatcher
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    /** @var list<DestinationGate> */
    public array $gates = [];

    public function __construct(public readonly Sender $sender) {}

    public function add(Driver $driver): self
    {
        $this->drivers[$driver->binding()->scope.'|'.$driver->binding()->name] = $driver;

        return $this;
    }

    public function binding(string $connection, ?string $scope = null): BindingRef
    {
        return ($this->drivers[($scope ?? 'default').'|'.$connection] ?? throw new UnknownConnectionException('No connection '.$connection.'.'))->binding();
    }

    public function send(BindingRef $binding, Message $message, iterable $destinations, ?SendOptions $options = null): SendReport
    {
        $driver = $this->drivers[$binding->scope.'|'.$binding->name] ?? null;
        if ($driver === null || ! $driver->binding()->sameIdentityAs($binding)) {
            return new SendReport((static function () use ($destinations): \Generator {
                foreach ($destinations as $value) {
                    $item = SendItem::of($value);
                    yield new SendResult($item->destination, SendStatus::Skipped, Reason::of(ReasonCode::BindingMismatch), deliveryId: $item->deliveryId);
                }
            })());
        }

        return $this->sender->send($driver, $message, $destinations, $options ?? $this->defaultOptions($binding));
    }

    public function driver(BindingRef $binding): Driver
    {
        return $this->drivers[$binding->scope.'|'.$binding->name] ?? throw new UnknownConnectionException('No connection '.$binding->name.'.');
    }

    public function defaultOptions(BindingRef $binding): SendOptions
    {
        $options = SendKit::live();
        foreach ($this->gates as $gate) {
            $options = $options->withGate($gate);
        }

        return $options;
    }
}

/** A worker process dying at a chosen point. */
final class WorkerKilled extends \RuntimeException {}
