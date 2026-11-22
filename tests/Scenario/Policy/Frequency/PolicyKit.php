<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Policy\Frequency;

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\ReportsAvailability;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCapGate;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\StoreFailurePolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/** The real Sender and FakeTransport around the policy gates, over a shared in-memory store and a fake clock. */
final class PolicyKit
{
    public readonly SendKit $send;

    public readonly FakeClock $clock;

    public readonly InMemoryGuardStore $store;

    public function __construct()
    {
        $this->send = new SendKit;
        $this->clock = $this->send->clock;
        $this->store = $this->send->guardStore;
    }

    /** @param  array<mixed>  $table */
    public function gate(array $table, StoreFailurePolicy $onFailure = StoreFailurePolicy::Allow, ?string $noPolicy = 'default', ?GuardStore $store = null): FrequencyCapGate
    {
        return new FrequencyCapGate(
            new FrequencyCounter($store ?? $this->store, $this->clock),
            FrequencyLimits::fromArray($table),
            $this->send->index,
            $onFailure,
            $noPolicy,
        );
    }

    /** A registry device: token number `$i`, belonging to user `$owner` (null = no owner). */
    public static function device(int $i, ?string $owner, string $ownerType = 'App\\Models\\User'): ApnsDeviceToken
    {
        return SendKit::apnsToken($i)->withRef(new DestinationRef('dev-'.$i, 1, $owner === null ? null : $ownerType, $owner));
    }

    /**
     * Sends one effect through the real Sender; returns the transport (the oracle) and the results.
     *
     * @param  list<Destination>  $destinations
     * @return array{0: FakeTransport, 1: list<SendResult>}
     */
    public function deliver(FrequencyCapGate $gate, PushMessage $message, array $destinations, ?string $effectKey, string $scope = 'default', bool $fcm = false): array
    {
        $transport = new FakeTransport;
        $driver = $fcm
            ? $this->send->fcmDriver(binding: SendKit::fcmBinding(scope: $scope))
            : $this->send->apnsDriver($this->send->apnsCredential(scope: $scope), null, SendKit::apnsBinding(scope: $scope));
        $options = SendKit::live(trafficClass: TrafficClass::Bulk, gates: [$gate], effectKey: $effectKey);

        return [$transport, SendKit::all($this->send->sender($transport)->send($driver, $message, $destinations, $options))];
    }

    /** @param  list<SendResult>  $results @return list<string> */
    public static function statuses(array $results): array
    {
        return array_map(static fn (SendResult $r): string => $r->status->value, $results);
    }
}

/** A second worker sneaking in between a read and the compare-and-set of the first (the classic lost-update race). */
final class InterleavingGuardStore implements GuardStore
{
    public ?\Closure $beforeNextCompareAndSet = null;

    public function __construct(private readonly GuardStore $inner) {}

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        if ($this->beforeNextCompareAndSet !== null) {
            $hook = $this->beforeNextCompareAndSet;
            $this->beforeNextCompareAndSet = null;
            $hook();
        }

        return $this->inner->compareAndSet($key, $expected, $value, $ttlSeconds);
    }
}

/** A cache that is down and says so loudly. */
final class ThrowingGuardStore implements GuardStore
{
    public function get(string $key): ?string
    {
        throw new \RuntimeException('cache down');
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        throw new \RuntimeException('cache down');
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        throw new \RuntimeException('cache down');
    }
}

/**
 * A cache that is down and does not throw: what CacheGuardStore does (get null, put no-op, compareAndSet false, and
 * available() false, which is how it is told apart from a busy store).
 */
final class SilentDeadGuardStore implements GuardStore, ReportsAvailability
{
    public int $compareAndSets = 0;

    public function available(): bool
    {
        return false;
    }

    public function get(string $key): ?string
    {
        return null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void {}

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        $this->compareAndSets++;

        return false;
    }
}

/**
 * A cache that is ALIVE and busy: another writer wins the first `$losses` compare-and-sets (null = every one), as
 * happens to a worker among many on a slow shared store. Reads and the winning writes go to the inner store.
 */
final class BusyGuardStore implements GuardStore, ReportsAvailability
{
    public int $compareAndSets = 0;

    public function __construct(private readonly GuardStore $inner, private readonly ?int $losses = null) {}

    public function available(): bool
    {
        return true;
    }

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        $this->compareAndSets++;
        if ($this->losses === null || $this->compareAndSets <= $this->losses) {
            return false;
        }

        return $this->inner->compareAndSet($key, $expected, $value, $ttlSeconds);
    }
}

/** A store that remembers what it was asked: every compare-and-set and put, with its key and TTL. */
final class RecordingGuardStore implements GuardStore
{
    /** @var list<array{0: string, 1: string, 2: int}> method, key, TTL */
    public array $writes = [];

    public function __construct(private readonly GuardStore $inner) {}

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->writes[] = ['put', $key, $ttlSeconds];
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        $this->writes[] = ['cas', $key, $ttlSeconds];

        return $this->inner->compareAndSet($key, $expected, $value, $ttlSeconds);
    }
}

/**
 * A cache under memory pressure that has evicted every key starting with `$prefix` (memcached LRU, Redis maxmemory):
 * reads of those keys miss and writes to them are accepted and forgotten. Everything else goes to the inner store.
 */
final class EvictingGuardStore implements GuardStore
{
    public function __construct(private readonly GuardStore $inner, private readonly string $prefix) {}

    public function get(string $key): ?string
    {
        return str_starts_with($key, $this->prefix) ? null : $this->inner->get($key);
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        if (! str_starts_with($key, $this->prefix)) {
            $this->inner->put($key, $value, $ttlSeconds);
        }
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        return str_starts_with($key, $this->prefix) ? $expected === null : $this->inner->compareAndSet($key, $expected, $value, $ttlSeconds);
    }
}

/** Runs `$hook($key)` once, right before the first compare-and-set on a key starting with `$prefix`. */
final class PrefixInterleavingGuardStore implements GuardStore
{
    public ?\Closure $hook = null;

    public function __construct(private readonly GuardStore $inner, private readonly string $prefix) {}

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        if ($this->hook !== null && str_starts_with($key, $this->prefix)) {
            $hook = $this->hook;
            $this->hook = null;
            $hook($key);
        }

        return $this->inner->compareAndSet($key, $expected, $value, $ttlSeconds);
    }
}

/** An installation destination the TokenIndex cannot index (its value cannot be read). */
final class UnindexableDestination implements Destination
{
    public function kind(): DestinationKind
    {
        return DestinationKind::ApnsDeviceToken;
    }

    public function value(): string
    {
        throw new \RuntimeException('value unavailable');
    }

    public function ref(): ?DestinationRef
    {
        return null;
    }

    public function withRef(DestinationRef $ref): static
    {
        return $this;
    }

    public function equals(Destination $other): bool
    {
        return $other === $this;
    }
}

/** The keys and decoded values an InMemoryGuardStore holds now (expired entries included until read). */
final class StoreDump
{
    /** @return array<string, string> key => raw value, live entries only */
    public static function of(InMemoryGuardStore $store): array
    {
        $keys = array_keys((new \ReflectionProperty($store, 'data'))->getValue($store));
        $out = [];
        foreach ($keys as $key) {
            $value = $store->get((string) $key);
            if ($value !== null) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function keysStartingWith(InMemoryGuardStore $store, string $prefix): array
    {
        return array_values(array_filter(array_keys(self::of($store)), static fn (string $k): bool => str_starts_with($k, $prefix)));
    }

    /** The single key with this prefix (fails loudly otherwise). */
    public static function onlyKey(InMemoryGuardStore $store, string $prefix): string
    {
        $keys = self::keysStartingWith($store, $prefix);
        if (count($keys) !== 1) {
            throw new \LogicException(count($keys).' keys start with '.$prefix);
        }

        return $keys[0];
    }

    /** @return array<mixed> */
    public static function decoded(InMemoryGuardStore $store, string $prefix): array
    {
        $decoded = json_decode((string) $store->get(self::onlyKey($store, $prefix)), true);

        return is_array($decoded) ? $decoded : [];
    }
}
