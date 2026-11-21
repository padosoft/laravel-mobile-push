<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Logging\PushLog;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Where the package keeps data about an owner. Every accessor returns null when that store does not exist in this
 * installation (module not installed, table not migrated), so a forget or an export reports "not present" instead of
 * failing, and never depends on a module being enabled.
 */
final class OwnerStores
{
    private const ACTIVITY = 'Padosoft\\MobilePush\\Laravel\\LiveActivities\\PushActivity';

    private const PUSH_TO_START = 'Padosoft\\MobilePush\\Laravel\\LiveActivities\\PushToStartToken';

    public function __construct(private readonly Container $container) {}

    /** The scope given, or the current one (the push manager's scope resolver, else `mobile-push.scope`). */
    public function scope(?string $scope): string
    {
        if ($scope !== null && trim($scope) !== '') {
            return Scope::normalize($scope);
        }

        if ($this->container->bound(PushManager::class)) {
            return $this->container->make(PushManager::class)->currentScope();
        }

        $configured = config('mobile-push.scope', 'default');

        return Scope::normalize(is_string($configured) ? $configured : null);
    }

    /** @return Builder<Model>|null */
    public function devices(): ?Builder
    {
        $class = PushDevice::modelClass();

        return $this->builder(new $class);
    }

    /** @return Builder<Model>|null */
    public function activities(): ?Builder
    {
        return $this->optional(self::ACTIVITY);
    }

    /** @return Builder<Model>|null */
    public function pushToStartTokens(): ?Builder
    {
        return $this->optional(self::PUSH_TO_START);
    }

    /** @return Builder<Model>|null */
    public function logs(): ?Builder
    {
        return $this->builder(new PushLog);
    }

    /** The durable ledger, when its tables exist. */
    public function ledger(): ?Ledger
    {
        return $this->ledgerTablesExist() && $this->container->bound(Ledger::class) ? $this->container->make(Ledger::class) : null;
    }

    public function deliveries(): ?QueryBuilder
    {
        $connection = $this->ledgerConnection();

        return $connection === null ? null : $connection->table(LedgerTables::fromConfig()->deliveries);
    }

    public function effects(): ?QueryBuilder
    {
        $connection = $this->ledgerConnection();

        return $connection === null ? null : $connection->table(LedgerTables::fromConfig()->effects);
    }

    private function ledgerTablesExist(): bool
    {
        return $this->ledgerConnection() !== null;
    }

    private function ledgerConnection(): ?Connection
    {
        if (! $this->container->bound(DatabaseLedger::class)) {
            return null;
        }

        $tables = LedgerTables::fromConfig();
        $connection = $this->container->make(DatabaseLedger::class)->connection();
        $schema = $connection->getSchemaBuilder();

        return $schema->hasTable($tables->deliveries) && $schema->hasTable($tables->effects) ? $connection : null;
    }

    /** @return Builder<Model>|null */
    private function optional(string $class): ?Builder
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $this->builder(new $class);
    }

    /** @return Builder<Model>|null */
    private function builder(Model $model): ?Builder
    {
        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable()) ? $model->newQuery() : null;
    }
}
