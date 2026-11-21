<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\LedgerTables;
use Psr\Clock\ClockInterface;

/**
 * Read-only questions the ledger interface does not answer (what a prune WOULD delete, when the next deferred delivery
 * is due), asked of the database ledger directly. Counters and timestamps only.
 */
final readonly class LedgerQueries
{
    public function __construct(private Container $container) {}

    public function available(): bool
    {
        return $this->container->bound(DatabaseLedger::class);
    }

    /** The earliest `next_attempt_at` among deferred deliveries (optionally of one effect), or null. */
    public function earliestDeferred(string $scope, ?string $effectId = null): ?\DateTimeImmutable
    {
        if (! $this->available()) {
            return null;
        }

        $query = $this->deliveries()->where('scope', $scope)->where('state', DeliveryState::Deferred->value);
        if ($effectId !== null) {
            $query->where('effect_id', $effectId);
        }
        $at = $query->min('next_attempt_at');

        return is_numeric($at) ? LedgerTime::date((int) $at) : null;
    }

    /**
     * What DatabaseLedger::prune() would delete with this retention: terminal deliveries whose effect is past its
     * dedupe window, then the effects left without deliveries. Same predicates as the real prune.
     *
     * @return array{deliveries: int, effects: int}
     */
    public function prunable(int $retentionSeconds, ?string $scope): array
    {
        $tables = LedgerTables::fromConfig();
        $now = LedgerTime::ms($this->container->make(ClockInterface::class)->now());
        $cutoff = $now - $retentionSeconds * 1000;
        $terminal = array_map(static fn (DeliveryState $s): string => $s->value, array_values(array_filter(DeliveryState::cases(), static fn (DeliveryState $s): bool => $s->isTerminal())));

        $deliveries = $this->deliveries()
            ->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', $scope))
            ->whereIn('state', $terminal)
            ->where('updated_at', '<', $cutoff)
            ->whereIn('effect_id', fn (Builder $q): Builder => $q->select('id')->from($tables->effects)->where('dedupe_until', '<=', $now))
            ->count();

        // Effects whose every delivery would be pruned (or that have none) and whose dedupe window has passed.
        $effects = $this->connection()->table($tables->effects)
            ->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', $scope))
            ->where('dedupe_until', '<=', $now)
            ->whereNotExists(fn (Builder $q): Builder => $q->selectRaw('1')->from($tables->deliveries)
                ->whereColumn($tables->deliveries.'.effect_id', $tables->effects.'.id')
                ->where(static function (Builder $keep) use ($terminal, $cutoff): void {
                    $keep->whereNotIn('state', $terminal)->orWhere('updated_at', '>=', $cutoff);
                }))
            ->count();

        return ['deliveries' => $deliveries, 'effects' => $effects];
    }

    private function deliveries(): Builder
    {
        return $this->connection()->table(LedgerTables::fromConfig()->deliveries);
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return $this->container->make(DatabaseLedger::class)->connection();
    }
}
