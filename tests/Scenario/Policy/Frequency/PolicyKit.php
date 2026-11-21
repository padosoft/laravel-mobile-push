<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Policy\Frequency;

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
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

/** A cache that is down and says nothing: what CacheGuardStore does (get null, put no-op, compareAndSet false). */
final class SilentDeadGuardStore implements GuardStore
{
    public function get(string $key): ?string
    {
        return null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void {}

    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool
    {
        return false;
    }
}
