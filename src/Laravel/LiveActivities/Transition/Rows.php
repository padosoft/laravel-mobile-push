<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/**
 * Compare-and-set writes on a row with a `version` column. Every write of the Live Activities tables goes through
 * here: one UPDATE guarded by the version read before, so two writers can never interleave their fields.
 *
 * @internal
 */
final readonly class Rows
{
    public const MAX_ATTEMPTS = 8;

    public function __construct(private ClockInterface $clock) {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now())->setTimezone(date_default_timezone_get());
    }

    public function second(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * UPDATE ... SET <fields>, version = version + 1 WHERE id = ? AND version = <the row's version>.
     * On success the model is refreshed in memory with the written fields and the new version.
     *
     * @param  array<string, mixed>  $fields  arrays are JSON-encoded, enums stored by value, dates formatted
     */
    public function cas(Model $row, array $fields): bool
    {
        $version = $row->getAttribute('version');
        if (! is_int($version)) {
            throw new \LogicException('A versioned row needs an integer version.');
        }

        $now = $this->now();
        $encoded = ['version' => $version + 1, 'updated_at' => $row->fromDateTime($now)];
        foreach ($fields as $column => $value) {
            $encoded[$column] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $row->fromDateTime($value),
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                default => $value,
            };
        }

        $changed = $row->newQuery()
            ->whereKey($row->getKey())
            ->where('version', $version)
            ->toBase()
            ->update($encoded);

        if ($changed !== 1) {
            return false;
        }

        $row->forceFill(array_replace($fields, ['version' => $version + 1, 'updated_at' => $now]));
        $row->syncOriginal();

        return true;
    }

    /**
     * Hand out the next ticket number of a row (its `dispatch_seq` + 1), under compare-and-set.
     *
     * @template T of Model
     *
     * @param  T  $row
     * @return array{0: int, 1: T} the ticket and the row as of the write
     */
    public function nextSeq(Model $row): array
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $current = $attempt === 0 ? $row : $this->fresh($row);
            $seq = $current->getAttribute('dispatch_seq');
            $seq = (is_int($seq) ? $seq : 0) + 1;

            if ($this->cas($current, ['dispatch_seq' => $seq])) {
                return [$seq, $current];
            }
        }

        throw new \RuntimeException('Could not number the transition: the row kept changing under concurrent writers.');
    }

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @return T
     */
    public function fresh(Model $row): Model
    {
        $fresh = $row->newQuery()->whereKey($row->getKey())->first();

        if (! $fresh instanceof $row) {
            throw new \RuntimeException('The row disappeared while a transition was being applied.');
        }

        /** @var T $fresh */
        return $fresh;
    }
}
