<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Scope;

/**
 * For owner models (User, Team...): `$user->registerPushDevice(...)`, `$user->pushDestinations($binding)`.
 *
 * @phpstan-require-extends Model
 */
trait HasPushDevices
{
    /** @return MorphMany<PushDevice, $this> */
    public function pushDevices(): MorphMany
    {
        /** @var MorphMany<PushDevice, $this> $relation */
        $relation = $this->morphMany(PushDevice::modelClass(), 'owner', 'owner_type', 'owner_id');

        return $relation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function registerPushDevice(BindingRef $binding, Destination $destination, array $attributes = []): PushDevice
    {
        return app(DeviceRegistrar::class)->register($binding, $destination, $this, $attributes);
    }

    /**
     * Active devices of this owner as typed destinations carrying their DestinationRef: one per installation
     * (an FCM token row that also holds a FID is still one destination).
     *
     * A BindingRef selects exactly that scope + binding identity. A string selects a binding by name inside
     * $scope (default scope when null). Null selects every binding of the given scope: an owner id is never
     * read across tenants.
     *
     * @return list<Destination>
     */
    public function pushDestinations(BindingRef|string|null $binding = null, ?string $scope = null): array
    {
        $query = $this->pushDevices()->getQuery()->active();

        if ($binding instanceof BindingRef) {
            $query->forBinding($binding);
        } else {
            $query->forScope(Scope::normalize($scope));

            if ($binding !== null) {
                $query->where('binding_name', $binding);
            }
        }

        $destinations = [];
        foreach ($query->orderBy('id')->get() as $device) {
            $destinations[] = $device->destination();
        }

        return $destinations;
    }
}
