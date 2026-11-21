<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\DestinationRef;

/**
 * The check run immediately before the network for a registry destination: row present, active, same owner, same
 * generation. One indexed query per chunk.
 *
 * When several rules fail, the most informative cause is reported: a phone that changed owner AND was registered
 * again says `owner_changed` (the status is the same Skipped/PolicyIneligible either way).
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
        $rows = $this->rows($refs, $binding, ['state', 'generation', 'owner_type', 'owner_id']);

        $out = [];
        foreach ($refs as $ref) {
            $out[$ref->id] = $this->evaluate($ref, $rows[$ref->id] ?? null);
        }

        return $out;
    }

    /**
     * The verdict AND the full device row of every reference, in one query (what a relay needs to build the
     * destinations it is about to send).
     *
     * @param  list<DestinationRef>  $refs
     * @param  BindingRef|null  $binding  when given, a row of another scope/binding counts as missing
     * @return array<string, ResolvedDevice> keyed by DestinationRef::$id
     */
    public function resolveMany(array $refs, ?BindingRef $binding = null): array
    {
        $rows = $this->rows($refs, $binding, null);

        $out = [];
        foreach ($refs as $ref) {
            $row = $rows[$ref->id] ?? null;
            $out[$ref->id] = new ResolvedDevice($this->evaluate($ref, $row), $row);
        }

        return $out;
    }

    /**
     * @param  list<DestinationRef>  $refs
     * @param  list<string>|null  $columns  null = every column
     * @return array<string, PushDevice>
     */
    private function rows(array $refs, ?BindingRef $binding, ?array $columns): array
    {
        if ($refs === []) {
            return [];
        }

        $model = new (PushDevice::modelClass());
        // Primary key only: the binding is checked on the rows read. A `forBinding()` in SQL lets the planner pick the
        // (binding_identity, ...) index and read every device of the binding for each chunk (W5-P: measured on
        // SQLite, 17 ms per chunk of 500 at 25,000 devices growing linearly with the registry).
        $query = $model->newQuery()
            ->whereIn($model->getKeyName(), array_values(array_unique(array_map(static fn (DestinationRef $r): string => $r->id, $refs))));
        if ($columns !== null) {
            $query->select(array_values(array_unique([$model->getKeyName(), 'scope', 'binding_identity', ...$columns])));
        }
        $identity = $binding?->identity();

        $rows = [];
        foreach ($query->get() as $row) {
            if ($binding !== null && ($row->scope !== $binding->scope || $row->binding_identity !== $identity)) {
                continue;   // another scope or binding: counts as missing
            }
            $rows[PushDevice::keyOf($row)] = $row;
        }

        return $rows;
    }

    private function evaluate(DestinationRef $ref, ?PushDevice $row): Eligibility
    {
        return match (true) {
            $row === null => Eligibility::no(Ineligibility::RowMissing),
            $row->state !== DeviceState::Active => Eligibility::no(Ineligibility::StateNotActive),
            $row->owner_type !== $ref->ownerType || $row->owner_id !== $ref->ownerId => Eligibility::no(Ineligibility::OwnerChanged),
            $row->generation !== $ref->generation => Eligibility::no(Ineligibility::GenerationChanged),
            default => Eligibility::yes(),
        };
    }
}
