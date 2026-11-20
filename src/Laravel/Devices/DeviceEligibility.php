<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\DestinationRef;

/**
 * The check run immediately before the network for a registry destination: row present, active, same
 * generation, same owner. One indexed query per chunk.
 */
final readonly class DeviceEligibility
{
    public function check(DestinationRef $ref, ?BindingRef $binding = null): Eligibility
    {
        return $this->checkMany([$ref], $binding)[$ref->id];
    }

    /**
     * @param  list<DestinationRef>  $refs
     * @param  BindingRef|null  $binding  when given, a row of another scope/binding counts as missing
     * @return array<string, Eligibility> keyed by DestinationRef::$id
     */
    public function checkMany(array $refs, ?BindingRef $binding = null): array
    {
        if ($refs === []) {
            return [];
        }

        $model = new (PushDevice::modelClass());
        $query = $model->newQuery()
            ->whereIn($model->getKeyName(), array_values(array_unique(array_map(static fn (DestinationRef $r): string => $r->id, $refs))))
            ->select([$model->getKeyName(), 'state', 'generation', 'owner_type', 'owner_id']);

        if ($binding !== null) {
            $query->forBinding($binding);
        }

        /** @var array<string, PushDevice> $rows */
        $rows = [];
        foreach ($query->get() as $row) {
            $rows[PushDevice::keyOf($row)] = $row;
        }

        $out = [];
        foreach ($refs as $ref) {
            $out[$ref->id] = $this->evaluate($ref, $rows[$ref->id] ?? null);
        }

        return $out;
    }

    private function evaluate(DestinationRef $ref, ?PushDevice $row): Eligibility
    {
        return match (true) {
            $row === null => Eligibility::no(Ineligibility::RowMissing),
            $row->state !== DeviceState::Active => Eligibility::no(Ineligibility::StateNotActive),
            $row->generation !== $ref->generation => Eligibility::no(Ineligibility::GenerationChanged),
            $row->owner_type !== $ref->ownerType || $row->owner_id !== $ref->ownerId => Eligibility::no(Ineligibility::OwnerChanged),
            default => Eligibility::yes(),
        };
    }
}
