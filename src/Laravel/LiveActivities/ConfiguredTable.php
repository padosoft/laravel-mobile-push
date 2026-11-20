<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;

/**
 * Table, connection and model class of a Live Activities model come from `mobile-push.live_activities.*`.
 *
 * @phpstan-require-extends Model
 */
trait ConfiguredTable
{
    /** @return array{0: string, 1: string, 2: string} config key of the table, default table, config key of the model */
    abstract protected static function tableConfig(): array;

    /** @return class-string<static> */
    public static function modelClass(): string
    {
        [, , $modelKey] = static::tableConfig();
        $class = config($modelKey, static::class);

        return is_string($class) && is_a($class, static::class, true) ? $class : static::class;
    }

    public static function blank(): static
    {
        $class = static::modelClass();

        return new $class;
    }

    public function getTable(): string
    {
        [$key, $default] = static::tableConfig();
        $table = config($key, $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }

    public function getConnectionName(): ?string
    {
        $connection = config('mobile-push.live_activities.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /** The primary key or morph key of a model as a string (owner ids are stored as strings). */
    public static function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : throw new \InvalidArgumentException('A Live Activity owner needs a scalar primary key.');
    }
}
