<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceReach;

/**
 * A campaign as stored (a snapshot: read it again through CampaignManager::find() for fresh values). It never
 * holds the message or a destination: those live in the ledger. `planned` is the planner's checkpoint; the live
 * numbers come from CampaignManager::progress(), which reads the ledger.
 */
final readonly class Campaign
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $name,
        public BindingRef $binding,
        public ?string $effectId,
        public string $audienceType,
        public AudienceReach $reach,
        public ?string $title,
        public CampaignStatus $status,
        public ?\DateTimeImmutable $expiresAt,
        public ?int $ratePerMinute,
        public int $sliceSize,
        public ?string $queueConnection,
        public ?string $queue,
        public ?string $cursor,
        public ?string $upperBound,
        /** Unique destinations the planner has written to the ledger (checkpoint). */
        public int $planned,
        public int $slices,
        /** Audience entries that could not be turned into a destination. */
        public int $invalid,
        public ?\DateTimeImmutable $nextSliceAt,
        public ?string $sliceOwner,
        public ?\DateTimeImmutable $sliceLeaseUntil,
        public int $version,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $plannedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?string $lastError,
        /** @var array<string, int>|null the ledger counters by state when the campaign finished */
        public ?array $finalCounts,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    /** @param  object|array<mixed>  $stored */
    public static function fromRow(object|array $stored): self
    {
        $row = Millis::row($stored);
        $binding = json_decode(Millis::str($row, 'binding') ?? '{}', true);
        /** @var array{scope: string, name: string, provider: string, app_id: string, environment: string, generation: string} $binding */
        $binding = is_array($binding) ? $binding : [];
        $final = Millis::str($row, 'final_counts');
        $counts = null;
        if ($final !== null) {
            $decoded = json_decode($final, true);
            $counts = [];
            foreach (is_array($decoded) ? $decoded : [] as $state => $n) {
                $counts[(string) $state] = is_numeric($n) ? (int) $n : 0;
            }
        }

        return new self(
            Millis::str($row, 'id') ?? '',
            Millis::str($row, 'scope') ?? '',
            Millis::str($row, 'name') ?? '',
            BindingRef::fromArray($binding),
            Millis::str($row, 'effect_id'),
            Millis::str($row, 'audience_type') ?? '',
            AudienceReach::tryFrom(Millis::str($row, 'reach') ?? '') ?? AudienceReach::Mixed,
            Millis::str($row, 'title'),
            CampaignStatus::from(Millis::str($row, 'status') ?? ''),
            Millis::date(Millis::int($row, 'expires_at')),
            Millis::int($row, 'rate_per_minute'),
            Millis::int($row, 'slice_size') ?? 1000,
            Millis::str($row, 'queue_connection'),
            Millis::str($row, 'queue'),
            Millis::str($row, 'cursor'),
            Millis::str($row, 'upper_bound'),
            Millis::int($row, 'planned') ?? 0,
            Millis::int($row, 'slices') ?? 0,
            Millis::int($row, 'invalid') ?? 0,
            Millis::date(Millis::int($row, 'next_slice_at')),
            Millis::str($row, 'slice_owner'),
            Millis::date(Millis::int($row, 'slice_lease_until')),
            Millis::int($row, 'version') ?? 0,
            Millis::date(Millis::int($row, 'started_at')),
            Millis::date(Millis::int($row, 'planned_at')),
            Millis::date(Millis::int($row, 'finished_at')),
            Millis::str($row, 'last_error'),
            $counts,
            Millis::date(Millis::int($row, 'created_at')) ?? new \DateTimeImmutable('@0'),
            Millis::date(Millis::int($row, 'updated_at')) ?? new \DateTimeImmutable('@0'),
        );
    }

    /** Every slice has been written (the audience is fully planned). */
    public function planningFinished(): bool
    {
        return $this->plannedAt !== null;
    }
}
