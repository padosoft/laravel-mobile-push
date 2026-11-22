<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Laravel\Cache\CacheLocks;
use Psr\Clock\ClockInterface;

/**
 * Cheap, cache-backed counters shared by every worker.
 *
 * Label set (the ONLY labels ever used, all bounded): provider, connection (binding name), environment, status, reason
 * (ReasonCode value), class (traffic class), and for circuit metrics guard + transition. Never a user id, device id,
 * token, campaign id, message id or destination: those are not even passed to this class.
 *
 * Storage: one cache key per series (`<prefix>:metrics:c:<sha1>`), incremented atomically (`Repository::increment`),
 * plus one JSON index key (`<prefix>:metrics:index`) listing the series so a scrape can enumerate them. The series count
 * is capped (`metrics.max_series`, default 1000): beyond it new series are silently not recorded. Counters are
 * monotonic and survive as long as the cache entry does (the cache server is the system of record). On the redis,
 * array and file stores they carry no TTL. The database, memcached and dynamodb stores do not create a key on
 * increment: there a counter is created with add() and lives one year, then restarts from zero. A restart, a cache
 * flush or an eviction resets a counter to zero, which Prometheus rate() treats as a normal counter reset. Gauges derived from events (open circuits, throttled accounts) are refreshed on each
 * event and carry a TTL. The per-minute window used for the error rate lives only `window + 120` seconds.
 * `reset()` forgets the series; the window buckets simply expire.
 *
 * Recording never throws and is a no-op when metrics are disabled. When the cache is down it silently does nothing.
 */
final class MetricsRecorder
{
    public const RESULTS = 'mobile_push_results_total';

    public const THROTTLED = 'mobile_push_throttled_total';

    public const TRANSITIONS = 'mobile_push_guard_transitions_total';

    public const CIRCUIT_OPEN = 'mobile_push_circuit_open';

    public const ACCOUNT_THROTTLED = 'mobile_push_account_throttled';

    private const HELP = [
        self::RESULTS => 'Send outcomes by provider, connection, environment, status, reason code and traffic class.',
        self::THROTTLED => 'Times a connection was paused by a provider throttle (HTTP 429 / quota), by traffic class.',
        self::TRANSITIONS => 'Circuit breaker transitions by guard and transition.',
        self::CIRCUIT_OPEN => '1 while the circuit of this guard is open or half-open for the connection, else 0.',
        self::ACCOUNT_THROTTLED => '1 while the connection is paused by a provider throttle, else 0.',
    ];

    private const STATE_TTL = 604800;

    /** Life of a counter on the stores where it has to be created with add(): a year, then it restarts from zero. */
    private const COUNTER_TTL = 31_536_000;

    /** Tries of one increment when a database-backed store answers with a contention error. */
    private const BUMP_ATTEMPTS = 10;

    /** @var array<string, int> series key => timestamp of the last index verification (re-checked every minute so a cache flush heals) */
    private array $known = [];

    /** @var array<string, true> */
    private array $rejected = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly ClockInterface $clock,
        private readonly bool $enabled = true,
        private readonly string $prefix = 'mobile-push',
        private readonly int $maxSeries = 1000,
        private readonly int $windowSeconds = 300,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function record(BindingRef $binding, SendResult $result, TrafficClass $class): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $labels = self::bindingLabels($binding) + [
                'status' => $result->status->value,
                'reason' => $result->reason->code->value,
                'class' => $class->value,
            ];
            $this->increment(self::RESULTS, $labels);

            if (ErrorRate::isSample($result->status)) {
                $this->recordWindow($binding, ErrorRate::isError($result->status, $result->reason->code));
            }
        } catch (\Throwable) {
            // Metrics must never break sending.
        }
    }

    /** The connection was paused by a throttle (until $pausedUntil, when known). */
    public function recordThrottle(BindingRef $binding, TrafficClass $class, ?\DateTimeImmutable $pausedUntil = null): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $labels = self::bindingLabels($binding) + ['class' => $class->value];
            $this->increment(self::THROTTLED, $labels);
            $until = $pausedUntil?->getTimestamp() ?? ($this->clock->now()->getTimestamp() + 60);
            $this->setState(self::ACCOUNT_THROTTLED, $labels, $until, max(60, $until - $this->clock->now()->getTimestamp() + 60));
        } catch (\Throwable) {
        }
    }

    public function recordGuardTransition(GuardTransition $transition): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $base = self::bindingLabels($transition->binding) + ['guard' => $transition->guard->value];
            $this->increment(self::TRANSITIONS, $base + ['transition' => $transition->transition->value]);

            $open = match ($transition->transition) {
                BreakerTransition::Opened, BreakerTransition::Reopened, BreakerTransition::HalfOpened => 1,
                BreakerTransition::Closed => 0,
                default => null,
            };
            if ($open !== null) {
                $this->setState(self::CIRCUIT_OPEN, $base, $open, self::STATE_TTL);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Everything recorded so far. Empty when the cache is unreachable or metrics are disabled.
     *
     * @return list<Metric>
     */
    public function snapshot(): array
    {
        if (! $this->enabled) {
            return [];
        }

        try {
            $index = $this->loadIndex();
            if ($index === []) {
                return [];
            }
            $values = $this->cache->many(array_keys($index));
            $now = $this->clock->now()->getTimestamp();
            $metrics = [];
            foreach ($index as $key => $entry) {
                if ($entry['k'] === 'b') {
                    continue;
                }
                $raw = $values[$key] ?? null;
                if (! is_numeric($raw)) {
                    continue;
                }
                $value = (int) $raw;
                $isCounter = $entry['k'] === 'c';
                if (! $isCounter && $entry['n'] === self::ACCOUNT_THROTTLED) {
                    $value = $value > $now ? 1 : 0;
                }
                $metrics[] = new Metric($entry['n'], $isCounter ? MetricType::Counter : MetricType::Gauge, self::HELP[$entry['n']] ?? $entry['n'], $entry['l'], $value);
            }

            return $metrics;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string, string>> label sets of circuits currently open (or half-open) */
    public function openCircuits(): array
    {
        return $this->activeGauges(self::CIRCUIT_OPEN);
    }

    /** @return list<array<string, string>> label sets of connections currently paused by a throttle */
    public function throttledAccounts(): array
    {
        return $this->activeGauges(self::ACCOUNT_THROTTLED);
    }

    /**
     * Samples and errors per binding over the last $windowSeconds (default and maximum: the configured window).
     *
     * @return list<ErrorRateSample>
     */
    public function errorRates(?int $windowSeconds = null): array
    {
        if (! $this->enabled) {
            return [];
        }

        try {
            $window = min($this->windowSeconds, max(60, $windowSeconds ?? $this->windowSeconds));
            $last = intdiv($this->clock->now()->getTimestamp(), 60);
            $first = $last - intdiv($window, 60) + 1;
            $samples = [];
            foreach ($this->loadIndex() as $entry) {
                if ($entry['k'] !== 'b') {
                    continue;
                }
                $labels = $entry['l'];
                $binding = ['provider' => $labels['provider'] ?? '', 'connection' => $labels['connection'] ?? '', 'environment' => $labels['environment'] ?? ''];
                $keys = [];
                for ($bucket = $first; $bucket <= $last; $bucket++) {
                    $keys['s'.$bucket] = $this->windowKey($binding, $bucket, 's');
                    $keys['e'.$bucket] = $this->windowKey($binding, $bucket, 'e');
                }
                $values = $this->cache->many(array_values($keys));
                $sample = 0;
                $errors = 0;
                foreach ($keys as $name => $key) {
                    $v = is_numeric($values[$key] ?? null) ? (int) $values[$key] : 0;
                    $name[0] === 's' ? $sample += $v : $errors += $v;
                }
                $samples[] = new ErrorRateSample($binding, $sample, $errors);
            }

            return $samples;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Forget every recorded series (the per-minute window buckets expire by themselves). */
    public function reset(): void
    {
        try {
            $index = $this->loadIndex();
            foreach (array_keys($index) as $key) {
                $this->cache->forget($key);
            }
            $this->cache->forget($this->indexKey());
        } catch (\Throwable) {
        }
        $this->known = [];
        $this->rejected = [];
    }

    /** @return list<array<string, string>> */
    private function activeGauges(string $name): array
    {
        $active = [];
        foreach ($this->snapshot() as $metric) {
            if ($metric->name === $name && $metric->value === 1) {
                $active[] = $metric->labels;
            }
        }

        return $active;
    }

    /** @param  array<string, string>  $labels */
    private function increment(string $name, array $labels): void
    {
        $key = $this->seriesKey('c', $name, $labels);
        if (! $this->ensureIndexed($key, 'c', $name, $labels)) {
            return;
        }
        $this->bump($key, self::COUNTER_TTL, false);
    }

    /**
     * Adds one to a counter key, whatever the cache store, without losing counts between processes:
     *  - the database, memcached and dynamodb stores do not create a key on increment (they answer false): the key
     *    is created with add(), which those stores do atomically, and incremented again;
     *  - a database-backed store answers contention with an error (SQLite "database is locked" when two
     *    transactions upgrade their lock, a MySQL deadlock): the increment ran in a transaction that was rolled
     *    back, so it is tried again a few times;
     *  - the file store increments by reading and writing the file: it is serialised with the store's own lock.
     *
     * `$createFirst` is for keys that must carry a TTL on every store (the per-minute window buckets).
     *
     * @throws \Throwable when the store fails for another reason (the public methods swallow it)
     */
    private function bump(string $key, int $ttl, bool $createFirst): void
    {
        $lock = $this->cache->getStore() instanceof FileStore ? CacheLocks::acquire($this->cache, $key.':lock', 5) : null;

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    if ($createFirst) {
                        $this->cache->add($key, 0, $ttl);
                    }
                    if ($this->cache->increment($key) === false) {
                        $this->cache->add($key, 0, $ttl);
                        $this->cache->increment($key);
                    }

                    return;
                } catch (\Throwable $e) {
                    if ($attempt >= self::BUMP_ATTEMPTS || ! CacheLocks::contention($e)) {
                        throw $e;
                    }
                    usleep(random_int(300, 3000));
                }
            }
        } finally {
            if ($lock !== null) {
                try {
                    CacheLocks::release($lock);
                } catch (\Throwable) {
                    // the lock expires by itself in a few seconds
                }
            }
        }
    }

    /** @param  array<string, string>  $labels */
    private function setState(string $name, array $labels, int $value, int $ttl): void
    {
        $key = $this->seriesKey('s', $name, $labels);
        if (! $this->ensureIndexed($key, 's', $name, $labels)) {
            return;
        }
        $this->cache->put($key, $value, $ttl);
    }

    private function recordWindow(BindingRef $binding, bool $error): void
    {
        $labels = self::bindingLabels($binding);
        $key = $this->seriesKey('b', '', $labels);
        if (! $this->ensureIndexed($key, 'b', '', $labels)) {
            return;
        }
        $bucket = intdiv($this->clock->now()->getTimestamp(), 60);
        $ttl = $this->windowSeconds + 120;
        $kinds = $error ? ['s', 'e'] : ['s'];
        foreach ($kinds as $kind) {
            $this->bump($this->windowKey($labels, $bucket, $kind), $ttl, true);
        }
    }

    /** @param  array<string, string>  $binding */
    private function windowKey(array $binding, int $bucket, string $kind): string
    {
        return $this->prefix.':metrics:w:'.sha1($this->seriesKey('b', '', $binding)).':'.$bucket.':'.$kind;
    }

    /** @param  array<string, string>  $labels */
    private function seriesKey(string $kind, string $name, array $labels): string
    {
        ksort($labels);

        return $this->prefix.':metrics:'.$kind.':'.sha1($name.'|'.json_encode($labels, JSON_THROW_ON_ERROR));
    }

    private function indexKey(): string
    {
        return $this->prefix.':metrics:index';
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function ensureIndexed(string $key, string $kind, string $name, array $labels): bool
    {
        $now = $this->clock->now()->getTimestamp();
        if (isset($this->known[$key]) && $now - $this->known[$key] < 60) {
            return true;
        }
        if (isset($this->rejected[$key])) {
            return false;
        }

        $index = $this->loadIndex();
        if (! isset($index[$key])) {
            $added = false;
            $this->withIndexLock(function () use ($key, $kind, $name, $labels, &$added): void {
                $index = $this->loadIndex();
                if (isset($index[$key])) {
                    $added = true;

                    return;
                }
                if (count($index) >= $this->maxSeries) {
                    return;
                }
                ksort($labels);
                $index[$key] = ['k' => $kind, 'n' => $name, 'l' => $labels];
                $this->cache->forever($this->indexKey(), json_encode($index, JSON_THROW_ON_ERROR));
                $added = true;
            });
            if (! $added) {
                $this->rejected[$key] = true;

                return false;
            }
        }
        $this->known[$key] = $now;

        return true;
    }

    /**
     * Best effort: if the lock cannot be taken in ~100 ms the update goes ahead unlocked (a lost index entry self-heals on the next process).
     *
     * @param  \Closure(): void  $work
     */
    private function withIndexLock(\Closure $work): void
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            $work();

            return;
        }
        $lock = $store->lock($this->indexKey().':lock', 10);
        $acquired = false;
        for ($i = 0; $i < 50 && ! $acquired; $i++) {
            try {
                $acquired = (bool) $lock->get();
            } catch (\Throwable $e) {
                // A database-backed store answers a contended lock with an error (a MySQL deadlock): not taken.
                if (! CacheLocks::contention($e)) {
                    throw $e;
                }
            }
            if (! $acquired) {
                usleep(2000);
            }
        }
        try {
            $work();
        } finally {
            if ($acquired) {
                try {
                    CacheLocks::release($lock);
                } catch (\Throwable) {
                    // the lock expires by itself in a few seconds
                }
            }
        }
    }

    /** @return array<string, array{k: string, n: string, l: array<string, string>}> */
    private function loadIndex(): array
    {
        $raw = $this->cache->get($this->indexKey());
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        $index = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_string($entry['k'] ?? null) && is_string($entry['n'] ?? null) && is_array($entry['l'] ?? null)) {
                $labels = [];
                foreach ($entry['l'] as $labelName => $labelValue) {
                    if (is_string($labelName) && is_string($labelValue)) {
                        $labels[$labelName] = $labelValue;
                    }
                }
                $index[$key] = ['k' => $entry['k'], 'n' => $entry['n'], 'l' => $labels];
            }
        }

        return $index;
    }

    /** @return array{provider: string, connection: string, environment: string} */
    private static function bindingLabels(BindingRef $binding): array
    {
        return [
            'provider' => $binding->provider->value,
            'connection' => self::clean($binding->name),
            'environment' => $binding->environment->value,
        ];
    }

    private static function clean(string $value): string
    {
        $clean = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return substr($clean, 0, 64);
    }
}
