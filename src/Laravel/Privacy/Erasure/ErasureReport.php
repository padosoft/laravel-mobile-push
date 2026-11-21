<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

/**
 * The outcome of OwnerEraser::forget(): per store what was affected, and, always, what the package cannot reach.
 * It never names the owner id: the owner is described by type only.
 */
final readonly class ErasureReport
{
    /** What no command of this package can reach. Part of every report on purpose. */
    public const NOT_REACHED = [
        'Database backups and snapshots (the rows removed here still exist in them until they expire).',
        'External collectors: OpenTelemetry backends, log files and log channels already written or shipped, monitoring and alerting systems.',
        'Jobs still waiting in the queue on the light profile (their payload stays until a worker runs or discards them; the eligibility check stops the send).',
        'The providers\' own systems (Apple APNs, Google FCM) and notifications already delivered to the device.',
        'Your application\'s own tables, caches and logs.',
    ];

    public const NOTICE = 'This command alone does not make the application compliant with the GDPR or any other regulation: retention of backups and external systems, legal bases and deadlines remain your responsibility.';

    /** @param  list<StoreReport>  $stores */
    public function __construct(
        public string $ownerType,
        public string $scope,
        public array $stores,
    ) {}

    /** True when no store failed. */
    public function succeeded(): bool
    {
        foreach ($this->stores as $store) {
            if ($store->status === StoreStatus::Failed) {
                return false;
            }
        }

        return true;
    }

    public function affected(): int
    {
        return array_sum(array_map(static fn (StoreReport $s): int => $s->affected, $this->stores));
    }

    /** @return list<string> */
    public function notReached(): array
    {
        return self::NOT_REACHED;
    }

    /** @return list<string> human-readable lines */
    public function lines(): array
    {
        $lines = [sprintf('Forget report for an owner of type %s in scope "%s":', $this->ownerType, $this->scope)];
        foreach ($this->stores as $store) {
            $lines[] = sprintf(
                '  - %s: %s%s%s',
                $store->store,
                match ($store->status) {
                    StoreStatus::Done => $store->affected.' row(s) affected',
                    StoreStatus::NotPresent => 'not present, nothing touched',
                    StoreStatus::Failed => 'FAILED, rerun the command',
                },
                $store->detail === '' ? '' : ' ('.$store->detail.')',
                '',
            );
        }
        $lines[] = 'Not reached by this command:';
        foreach (self::NOT_REACHED as $item) {
            $lines[] = '  * '.$item;
        }
        $lines[] = self::NOTICE;

        return $lines;
    }

    /** @return array{owner_type: string, scope: string, succeeded: bool, stores: list<array{store: string, status: string, affected: int, detail: string}>, not_reached: list<string>, notice: string} */
    public function toArray(): array
    {
        return [
            'owner_type' => $this->ownerType,
            'scope' => $this->scope,
            'succeeded' => $this->succeeded(),
            'stores' => array_map(static fn (StoreReport $s): array => $s->toArray(), $this->stores),
            'not_reached' => self::NOT_REACHED,
            'notice' => self::NOTICE,
        ];
    }
}
