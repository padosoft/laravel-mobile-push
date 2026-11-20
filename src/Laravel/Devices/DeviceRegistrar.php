<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Psr\Clock\ClockInterface;

/**
 * The only writer of the device registry.
 *
 * Rules (docs/PLAN.md section 4.2, 4.4):
 *  - identity of a destination = binding identity (scope + provider + app + environment) + kind + token;
 *  - same owner and still active: refresh only, generation unchanged;
 *  - owner changed, or the row was quarantined/invalidated: generation + 1 and registered_at = now;
 *  - provider, token type, app and environment are declared by the caller, never inferred.
 *
 * Logout choice: detaching an owner keeps the row (the phone and its token still exist), clears the owner and
 * bumps the generation, so every queued push for the previous owner stops being eligible. The next login
 * registers it again (owner null -> new owner = another bump).
 */
final readonly class DeviceRegistrar
{
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private TokenIndex $index,
        private TokenCipher $cipher,
        private ClockInterface $clock,
    ) {}

    /**
     * Idempotent upsert.
     *
     * Attributes: platform (Platform|string), locale, timezone, app_version, os_version, device_model,
     * metadata (array, merged), fid (FcmFid, FCM token rows only).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidDestinationException before any write when the destination does not fit the binding
     */
    public function register(BindingRef $binding, Destination $destination, ?Model $owner, array $attributes = []): PushDevice
    {
        $this->assertRegistrable($binding, $destination);
        $fid = $this->fidFrom($binding, $destination, $attributes);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $existing = $this->find($binding, $destination);

            if ($existing === null) {
                try {
                    return $this->create($binding, $destination, $owner, $attributes, $fid);
                } catch (UniqueConstraintViolationException $e) {
                    // Another request registered the same destination first: treat it as a re-registration.
                    if ($attempt === self::MAX_ATTEMPTS) {
                        throw $e;
                    }

                    continue;
                }
            }

            $device = $this->touch($binding, $destination, $owner, $attributes, $fid, $existing->getKey());

            if ($device !== null) {
                return $device;
            }
            // The row vanished (pruned) between the lookup and the lock: start over.
        }

        throw new \RuntimeException('Could not register the device: the registry kept changing under concurrent writers.');
    }

    /** Attach the FCM installation id to an FCM token row: still ONE row, ONE send per effect. */
    public function attachFid(PushDevice $device, FcmFid $fid): PushDevice
    {
        if ($device->kind !== DestinationKind::FcmToken) {
            throw new InvalidDestinationException('A FID can only be attached to a device row that holds an FCM token.');
        }

        $device->fid = $this->cipher->encrypt($fid->value(), $device->scope, DestinationKind::FcmFid->value);
        $device->fid_index = $this->index->index($fid, $device->scope);
        $device->save();

        return $device;
    }

    /**
     * Logout of one destination: detach the owner and bump the generation. A different current owner is left alone
     * (A logging out late must not detach B). Returns whether a row changed.
     */
    public function unregister(BindingRef $binding, Destination $destination, ?Model $owner = null): bool
    {
        $this->assertRegistrable($binding, $destination);
        $model = $this->newModel();

        return (bool) $model->getConnection()->transaction(function () use ($binding, $destination, $owner, $model): bool {
            $found = $this->find($binding, $destination);
            $device = $found === null ? null : $model->newQuery()->whereKey($found->getKey())->lockForUpdate()->first();

            if ($device === null) {
                return false;
            }

            if ($owner !== null && ($device->owner_type !== $owner->getMorphClass() || $device->owner_id !== (string) $owner->getKey())) {
                return false;
            }

            $device->owner_type = null;
            $device->owner_id = null;
            $device->generation = $device->generation + 1;
            $device->save();

            return true;
        });
    }

    /**
     * Detach an owner from every device (all scopes unless one is given) and bump each generation, in one
     * statement. Returns the number of devices detached.
     */
    public function forgetOwner(Model $owner, ?string $scope = null): int
    {
        $model = $this->newModel();
        $query = $model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey());

        if ($scope !== null) {
            $query->where('scope', $scope);
        }

        return $query->update([
            'owner_type' => null,
            'owner_id' => null,
            'generation' => $model->getConnection()->raw('generation + 1'),
            'updated_at' => $model->fromDateTime($this->now()),
        ]);
    }

    private function assertRegistrable(BindingRef $binding, Destination $destination): void
    {
        $kind = $destination->kind();

        if (! $kind->isIndividual()) {
            throw new InvalidDestinationException('Only individual installations (tokens, FIDs) can be registered; topics, conditions and broadcast channels cannot.');
        }

        if ($kind->provider() !== $binding->provider) {
            throw new InvalidDestinationException("A {$kind->value} destination cannot be registered on a {$binding->provider->value} binding.");
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function fidFrom(BindingRef $binding, Destination $destination, array $attributes): ?FcmFid
    {
        $fid = $attributes['fid'] ?? null;

        if ($fid === null) {
            return null;
        }

        if (! $fid instanceof FcmFid) {
            throw new InvalidDestinationException('The "fid" attribute must be an FcmFid.');
        }

        if ($binding->provider !== Provider::Fcm || $destination->kind() !== DestinationKind::FcmToken) {
            throw new InvalidDestinationException('A FID can only accompany an FCM token registration.');
        }

        return $fid;
    }

    private function find(BindingRef $binding, Destination $destination): ?PushDevice
    {
        $model = $this->newModel();
        $query = $model->newQuery()
            ->where('binding_identity', $binding->identity())
            ->where('kind', $destination->kind()->value)
            ->whereIn('token_index', $this->index->candidates($destination, $binding->scope));

        $device = $query->first();

        if ($device === null && $destination->kind() === DestinationKind::FcmFid) {
            // One installation, one row: a token row that already holds this FID is the same device.
            $device = $model->newQuery()
                ->where('binding_identity', $binding->identity())
                ->whereIn('fid_index', $this->index->candidates($destination, $binding->scope))
                ->first();
        }

        return $device;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(BindingRef $binding, Destination $destination, ?Model $owner, array $attributes, ?FcmFid $fid): PushDevice
    {
        $now = $this->now();
        $device = $this->newModel();
        $device->forceFill([
            'scope' => $binding->scope,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner === null ? null : (string) $owner->getKey(),
            'binding_name' => $binding->name,
            'binding_identity' => $binding->identity(),
            'provider' => $binding->provider,
            'app_id' => $binding->appId,
            'environment' => $binding->environment,
            'kind' => $destination->kind(),
            'token' => $this->cipher->encrypt($destination->value(), $binding->scope, $destination->kind()->value),
            'token_index' => $this->index->index($destination, $binding->scope),
            'generation' => 1,
            'state' => DeviceState::Active,
            'state_changed_at' => $now,
            'registered_at' => $now,
            'last_seen_at' => $now,
        ]);
        $this->fillProfile($device, $attributes);
        $this->fillFid($device, $fid);

        $device->getConnection()->transaction(static function () use ($device): void {
            $device->save();
        });

        return $device;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function touch(BindingRef $binding, Destination $destination, ?Model $owner, array $attributes, ?FcmFid $fid, mixed $key): ?PushDevice
    {
        $model = $this->newModel();

        return $model->getConnection()->transaction(function () use ($model, $binding, $destination, $owner, $attributes, $fid, $key): ?PushDevice {
            $device = $model->newQuery()->whereKey($key)->lockForUpdate()->first();

            if ($device === null) {
                return null;
            }

            $now = $this->now();
            $ownerType = $owner?->getMorphClass();
            $ownerId = $owner === null ? null : (string) $owner->getKey();

            if ($device->state !== DeviceState::Active || $device->owner_type !== $ownerType || $device->owner_id !== $ownerId) {
                $device->generation = $device->generation + 1;
                $device->owner_type = $ownerType;
                $device->owner_id = $ownerId;
                $device->state = DeviceState::Active;
                $device->state_reason = null;
                $device->state_changed_at = $now;
                $device->registered_at = $now;
            }

            // Re-index to the current key version (and re-encrypt under the current key) on every touch.
            $current = $this->index->index($destination, $binding->scope);
            if ($destination->kind() === $device->kind) {
                $device->token_index = $current;

                if ($this->cipher->needsRotation($device->token)) {
                    $device->token = $this->cipher->encrypt($destination->value(), $binding->scope, $destination->kind()->value);
                }
            } elseif ($destination->kind() === DestinationKind::FcmFid) {
                $device->fid_index = $current;
            }

            $device->binding_name = $binding->name;
            $device->last_seen_at = $now;
            $this->fillProfile($device, $attributes);
            $this->fillFid($device, $fid);
            $device->save();

            return $device;
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function fillProfile(PushDevice $device, array $attributes): void
    {
        $platform = $attributes['platform'] ?? null;
        if ($platform instanceof Platform || is_string($platform)) {
            $device->platform = $platform instanceof Platform ? $platform : Platform::from($platform);
        }

        foreach (['locale', 'timezone', 'app_version', 'os_version', 'device_model'] as $column) {
            $value = $attributes[$column] ?? null;
            if (is_string($value) && $value !== '') {
                $device->{$column} = $value;
            }
        }

        $metadata = $attributes['metadata'] ?? null;
        if (is_array($metadata)) {
            /** @var array<string, mixed> $metadata */
            $device->metadata = array_replace($device->metadata ?? [], $metadata);
        }
    }

    private function fillFid(PushDevice $device, ?FcmFid $fid): void
    {
        if ($fid !== null) {
            $device->fid = $this->cipher->encrypt($fid->value(), $device->scope, DestinationKind::FcmFid->value);
            $device->fid_index = $this->index->index($fid, $device->scope);
        }
    }

    private function newModel(): PushDevice
    {
        $class = PushDevice::modelClass();

        return new $class;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now())->setTimezone(date_default_timezone_get());
    }
}
