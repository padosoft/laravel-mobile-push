<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Health;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/** An array store that remembers what it was asked and can be scripted to fail, answer false or hand out a scripted lock. */
class SpyStore extends ArrayStore
{
    /** @var list<string> "op key" */
    public array $log = [];

    /** @var list<\Throwable> thrown by the next increments, one per call */
    public array $incrementErrors = [];

    public bool $incrementCreates = true;

    public int $incrementCalls = 0;

    /** @var list<array{string, int}> */
    public array $lockRequests = [];

    public ?\Closure $lockFactory = null;

    public function get($key)
    {
        $this->log[] = 'get '.$key;

        return parent::get($key);
    }

    public function forever($key, $value)
    {
        $this->log[] = 'forever '.$key;

        return parent::forever($key, $value);
    }

    public function forget($key)
    {
        $this->log[] = 'forget '.$key;

        return parent::forget($key);
    }

    public function increment($key, $value = 1)
    {
        $this->incrementCalls++;
        $this->log[] = 'increment '.$key;
        if ($this->incrementErrors !== []) {
            throw array_shift($this->incrementErrors);
        }
        if (! $this->incrementCreates && parent::get($key) === null) {
            return false;
        }

        return parent::increment($key, $value);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->lockRequests[] = [$name, $seconds];
        if ($this->lockFactory !== null) {
            return ($this->lockFactory)($name, $seconds);
        }

        return parent::lock($name, $seconds, $owner);
    }

    public function count(string $op, string $key): int
    {
        return count(array_filter($this->log, static fn (string $l): bool => $l === $op.' '.$key));
    }
}

/** A lock whose get() answers from a script: true, false, or a throwable to throw. */
final class ScriptedLock implements Lock
{
    public int $gets = 0;

    public int $releases = 0;

    /** @param  list<bool|\Throwable>  $script  consumed one per get(); the last one repeats */
    public function __construct(private array $script, private readonly ?\Closure $onGet = null) {}

    public function get($callback = null)
    {
        $this->gets++;
        $step = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
        if ($step instanceof \Throwable) {
            throw $step;
        }
        if ($step && $this->onGet !== null) {
            ($this->onGet)();
        }

        return $step;
    }

    public function block($seconds, $callback = null)
    {
        return $this->get($callback);
    }

    public function release()
    {
        $this->releases++;

        return true;
    }

    public function owner()
    {
        return 'scripted';
    }

    public function forceRelease(): void {}
}

/** A store that is NOT a LockProvider (it only delegates to an array store). */
final class LocklessStore implements Store
{
    public ArrayStore $inner;

    public function __construct()
    {
        $this->inner = new ArrayStore;
    }

    public function get($key)
    {
        return $this->inner->get($key);
    }

    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function touch($key, $seconds)
    {
        return $this->inner->touch($key, $seconds);
    }

    public function getPrefix()
    {
        return '';
    }
}

/** A recorder on a spy store with a fake clock that Carbon follows, so TTLs elapse together with the recorder's clock. */
final class MetricsRig
{
    public const PREFIX = 'pfx';

    public FakeClock $clock;

    public Repository $repo;

    public MetricsRecorder $recorder;

    public function __construct(public SpyStore $store = new SpyStore, int $maxSeries = 1000, int $window = 300, bool $enabled = true)
    {
        $this->clock = new FakeClock('2026-11-20T10:00:00Z');
        Carbon::setTestNow(Carbon::instance($this->clock->now()));
        $this->repo = new Repository($this->store);
        $this->recorder = new MetricsRecorder($this->repo, $this->clock, $enabled, self::PREFIX, $maxSeries, $window);
    }

    public function advance(int $seconds): self
    {
        $this->clock->advance($seconds);
        Carbon::setTestNow(Carbon::instance($this->clock->now()));

        return $this;
    }

    public function record(BindingRef $binding, SendStatus $status, ReasonCode $code = ReasonCode::None, TrafficClass $class = TrafficClass::Bulk): void
    {
        $this->recorder->record($binding, HealthKit::result($status, $code), $class);
    }

    /** @return array<string, string> */
    public static function labels(string $conn, string $provider = 'apns', string $env = 'production'): array
    {
        return ['connection' => $conn, 'environment' => $env, 'provider' => $provider];
    }

    /** @param  array<string, string>  $labels */
    public static function seriesKey(string $kind, string $name, array $labels): string
    {
        ksort($labels);

        return self::PREFIX.':metrics:'.$kind.':'.sha1($name.'|'.json_encode($labels));
    }

    /** @param  array<string, string>  $binding */
    public static function windowKey(array $binding, int $bucket, string $kind): string
    {
        return self::PREFIX.':metrics:w:'.sha1(self::seriesKey('b', '', $binding)).':'.$bucket.':'.$kind;
    }

    public static function indexKey(): string
    {
        return self::PREFIX.':metrics:index';
    }

    /** @return array<string, mixed> */
    public function index(): array
    {
        return json_decode((string) $this->repo->get(self::indexKey()), true) ?: [];
    }

    public static function lockedError(): QueryException
    {
        return new QueryException('sqlite', 'update "cache" set "value" = ?', [], new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
    }
}
