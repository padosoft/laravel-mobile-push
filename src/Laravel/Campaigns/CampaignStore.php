<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\Audience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceRegistry;
use Psr\Clock\ClockInterface;

/**
 * The campaigns table. Every change is an UPDATE guarded by the row version (compare-and-set); the caller re-reads
 * and decides again when it lost. Every read is scoped: an id from another scope is not found.
 *
 * @internal
 */
final readonly class CampaignStore
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private ClockInterface $clock,
        private TokenCipher $cipher,
        private AudienceRegistry $audiences,
        private string $table = 'mobile_push_campaigns',
        private ?string $connectionName = null,
    ) {}

    public function connection(): Connection
    {
        $connection = $this->db->connection($this->connectionName);
        if (! $connection instanceof Connection) {
            throw new \LogicException('Campaigns need an Illuminate\Database\Connection.');
        }

        return $connection;
    }

    public function nowMs(): int
    {
        return Millis::of($this->clock->now());
    }

    public function find(string $scope, string $id): ?Campaign
    {
        $row = $this->query()->where('scope', Scope::normalize($scope))->where('id', $id)->first();

        return $row === null ? null : Campaign::fromRow($row);
    }

    public function get(string $scope, string $id): Campaign
    {
        return $this->find($scope, $id) ?? throw new CampaignNotFoundException('No campaign '.$id.' in scope '.Scope::normalize($scope).'.');
    }

    /** @param  array<string, mixed>  $row */
    public function insert(array $row): void
    {
        $this->query()->insert($row);
    }

    /**
     * Apply `$changes` if the row still has `$version` (and, when given, the planner lease `$owner`).
     *
     * @param  array<string, mixed>  $changes
     */
    public function cas(Campaign $campaign, array $changes, ?string $owner = null): bool
    {
        $query = $this->query()->where('scope', $campaign->scope)->where('id', $campaign->id)->where('version', $campaign->version);
        if ($owner !== null) {
            $query->where('slice_owner', $owner);
        }

        return $query->update($changes + ['version' => $campaign->version + 1, 'updated_at' => $this->nowMs()]) === 1;
    }

    /**
     * Note that the planner job for slice number `$slices` + 1 was queued. Monotonic and unversioned: it only ever
     * raises the marker, so it can race with any other write.
     */
    public function markSuccessor(Campaign $campaign, int $slices): void
    {
        $this->query()->where('scope', $campaign->scope)->where('id', $campaign->id)
            ->where(static fn (Builder $q): Builder => $q->whereNull('successor_for')->orWhere('successor_for', '<', $slices))
            ->update(['successor_for' => $slices]);
    }

    /** Whether the planner job for the slice after `$slices` is already queued. */
    public function successorQueued(Campaign $campaign, int $slices): bool
    {
        $marker = $this->query()->where('scope', $campaign->scope)->where('id', $campaign->id)->value('successor_for');

        return is_numeric($marker) && (int) $marker >= $slices;
    }

    /** Unversioned note of the last planner error (class only: no message can carry a destination). */
    public function noteError(string $scope, string $id, string $error): void
    {
        $this->query()->where('scope', Scope::normalize($scope))->where('id', $id)->update(['last_error' => substr($error, 0, 255)]);
    }

    /** Unversioned write of the throughput sample (display data only, never a decision). */
    public function sample(Campaign $campaign, int $atMs, int $done, ?float $throughput): void
    {
        $this->query()->where('scope', $campaign->scope)->where('id', $campaign->id)
            ->update(['sample_at' => $atMs, 'sample_done' => $done, 'throughput' => $throughput]);
    }

    /** @return array{0: string, 1: string} [stored type, encrypted parameters] */
    public function sealAudience(Audience $audience, string $scope, string $id): array
    {
        $json = json_encode($audience->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [$this->audiences->nameOf($audience), $this->cipher->encrypt($json, $scope, self::audienceContext($id))];
    }

    public function audience(Campaign $campaign): Audience
    {
        $sealed = $this->query()->where('scope', $campaign->scope)->where('id', $campaign->id)->value('audience');
        if (! is_string($sealed)) {
            throw new CampaignException('The campaign audience is missing.');
        }

        try {
            $parameters = json_decode($this->cipher->decrypt($sealed, $campaign->scope, self::audienceContext($campaign->id)), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException|\RuntimeException $e) {
            throw new CampaignException('The campaign audience cannot be read ('.$e::class.').');
        }
        if (! is_array($parameters)) {
            throw new CampaignException('The campaign audience cannot be read.');
        }
        /** @var array<string, mixed> $parameters */

        return $this->audiences->make($campaign->audienceType, $parameters);
    }

    /**
     * Campaigns of every scope in the given statuses, updated before `$beforeMs` (maintenance).
     *
     * @param  list<CampaignStatus>  $statuses
     * @return list<Campaign>
     */
    public function inStatus(array $statuses, int $limit, ?int $beforeMs = null): array
    {
        $rows = $this->query()
            ->whereIn('status', CampaignStatus::values(...$statuses))
            ->when($beforeMs !== null, static fn (Builder $q): Builder => $q->where('updated_at', '<', $beforeMs))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(static fn (object $row): Campaign => Campaign::fromRow($row), $rows->all()));
    }

    /**
     * Delete finished campaigns whose finish is older than `$cutoffMs`, in chunks. Returns the rows deleted.
     */
    public function pruneFinished(int $cutoffMs, int $chunk = 500, ?string $scope = null): int
    {
        $deleted = 0;
        do {
            $ids = $this->query()
                ->whereIn('status', CampaignStatus::values(CampaignStatus::Completed, CampaignStatus::Cancelled, CampaignStatus::Failed))
                ->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', Scope::normalize((string) $scope)))
                ->where(static fn (Builder $q): Builder => $q->where('finished_at', '<', $cutoffMs)
                    ->orWhere(static fn (Builder $q): Builder => $q->whereNull('finished_at')->where('updated_at', '<', $cutoffMs)))
                ->limit($chunk)
                ->pluck('id')->all();
            $deleted += $ids === [] ? 0 : $this->query()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return $deleted;
    }

    private function query(): Builder
    {
        return $this->connection()->table($this->table);
    }

    private static function audienceContext(string $id): string
    {
        return 'campaign-audience|'.$id;
    }
}
