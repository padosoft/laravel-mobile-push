<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

/**
 * Bounded LRU of resolved connections, keyed by binding fingerprint, with a side index from (scope, name, config
 * hash) to the fingerprint so the current config of a name is found without rebuilding the driver.
 *
 * A long-lived worker serving many tenants never holds more than `$limit` drivers: the least recently used one is
 * evicted and `$onEvict` closes its transport pools.
 */
final class ConnectionCache
{
    /** @var array<string, ResolvedConnection> fingerprint => connection, least recently used first */
    private array $entries = [];

    /** @var array<string, array{config: string, fingerprint: string}> "scope|name" => latest config hash and fingerprint */
    private array $latest = [];

    /** @var \Closure(ResolvedConnection): void */
    private \Closure $onEvict;

    /**
     * @param  (callable(ResolvedConnection): void)|null  $onEvict
     */
    public function __construct(private readonly int $limit, ?callable $onEvict = null)
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The connection cache needs room for at least one connection.');
        }
        $this->onEvict = $onEvict === null ? static function (ResolvedConnection $c): void {} : \Closure::fromCallable($onEvict);
    }

    /** The cached connection built from exactly this config for (scope, name), refreshed as most recently used. */
    public function find(string $scope, string $name, string $configHash): ?ResolvedConnection
    {
        $latest = $this->latest[self::slot($scope, $name)] ?? null;
        if ($latest === null || $latest['config'] !== $configHash) {
            return null;
        }

        return $this->touch($latest['fingerprint']);
    }

    public function get(string $fingerprint): ?ResolvedConnection
    {
        return $this->touch($fingerprint);
    }

    public function put(string $configHash, ResolvedConnection $connection): void
    {
        $binding = $connection->binding;
        $slot = self::slot($binding->scope, $binding->name);
        $fingerprint = $binding->fingerprint();

        // A new config for the same name replaces the previous driver of that name.
        $previous = $this->latest[$slot]['fingerprint'] ?? null;
        if ($previous !== null && $previous !== $fingerprint) {
            $this->evict($previous);
        }

        unset($this->entries[$fingerprint]);
        $this->entries[$fingerprint] = $connection;
        $this->latest[$slot] = ['config' => $configHash, 'fingerprint' => $fingerprint];

        while (count($this->entries) > $this->limit) {
            $this->evict((string) array_key_first($this->entries));
        }
    }

    /** Forget (and close) the connection of a name in a scope. */
    public function forget(string $scope, string $name): void
    {
        $fingerprint = $this->latest[self::slot($scope, $name)]['fingerprint'] ?? null;
        if ($fingerprint !== null) {
            $this->evict($fingerprint);
        }
    }

    public function flush(): void
    {
        foreach (array_keys($this->entries) as $fingerprint) {
            $this->evict($fingerprint);
        }
        $this->latest = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    private function touch(string $fingerprint): ?ResolvedConnection
    {
        $connection = $this->entries[$fingerprint] ?? null;
        if ($connection !== null) {
            unset($this->entries[$fingerprint]);
            $this->entries[$fingerprint] = $connection;
        }

        return $connection;
    }

    private function evict(string $fingerprint): void
    {
        $connection = $this->entries[$fingerprint] ?? null;
        unset($this->entries[$fingerprint]);
        if ($connection === null) {
            return;
        }
        $slot = self::slot($connection->binding->scope, $connection->binding->name);
        if (($this->latest[$slot]['fingerprint'] ?? null) === $fingerprint) {
            unset($this->latest[$slot]);
        }
        try {
            ($this->onEvict)($connection);
        } catch (\Throwable) {
            // closing pools is best effort
        }
    }

    private static function slot(string $scope, string $name): string
    {
        return $scope."\0".$name;
    }
}
