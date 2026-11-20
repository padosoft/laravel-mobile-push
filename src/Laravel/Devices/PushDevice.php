<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

/**
 * One installation of an app for one binding. Written only by DeviceRegistrar and DeviceOutcomeApplier.
 *
 * @property int $id
 * @property string $scope
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $binding_name
 * @property string $binding_identity
 * @property Provider $provider
 * @property Platform|null $platform
 * @property string $app_id
 * @property Environment $environment
 * @property DestinationKind $kind
 * @property string $token
 * @property string $token_index
 * @property string|null $fid
 * @property string|null $fid_index
 * @property int $generation
 * @property DeviceState $state
 * @property string|null $state_reason
 * @property CarbonInterface|null $state_changed_at
 * @property CarbonInterface $registered_at
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface|null $last_sent_at
 * @property array<string, mixed>|null $metadata
 */
class PushDevice extends Model
{
    /** Millisecond precision: APNs timestamps are compared against registered_at. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token', 'token_index', 'fid', 'fid_index'];

    /** @return class-string<PushDevice> */
    public static function modelClass(): string
    {
        $class = config('mobile-push.devices.model', self::class);

        return is_string($class) && is_a($class, self::class, true) ? $class : self::class;
    }

    public function getTable(): string
    {
        $table = config('mobile-push.devices.table', 'mobile_push_devices');

        return is_string($table) && $table !== '' ? $table : 'mobile_push_devices';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('mobile-push.devices.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'provider' => Provider::class,
            'platform' => Platform::class,
            'environment' => Environment::class,
            'kind' => DestinationKind::class,
            'state' => DeviceState::class,
            'generation' => 'integer',
            'metadata' => 'array',
            'state_changed_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /**
     * @param  Builder<PushDevice>  $query
     * @return Builder<PushDevice>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', DeviceState::Active->value);
    }

    /**
     * @param  Builder<PushDevice>  $query
     * @return Builder<PushDevice>
     */
    public function scopeForBinding(Builder $query, BindingRef $binding): Builder
    {
        return $query->where('scope', $binding->scope)->where('binding_identity', $binding->identity());
    }

    /**
     * @param  Builder<PushDevice>  $query
     * @return Builder<PushDevice>
     */
    public function scopeForScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    public function ref(): DestinationRef
    {
        return new DestinationRef((string) $this->getKey(), $this->generation, $this->owner_type, $this->owner_id);
    }

    /** The typed destination of this row (decrypted), carrying its DestinationRef. */
    public function destination(): Destination
    {
        return self::build($this->kind, $this->cipher()->decrypt($this->token, $this->scope, $this->kind->value))
            ->withRef($this->ref());
    }

    /** The FCM installation id of this row as a typed destination, when one is attached. */
    public function fidDestination(): ?FcmFid
    {
        if ($this->fid === null) {
            return null;
        }

        return FcmFid::of($this->cipher()->decrypt($this->fid, $this->scope, DestinationKind::FcmFid->value))
            ->withRef($this->ref());
    }

    public static function build(DestinationKind $kind, #[\SensitiveParameter] string $value): Destination
    {
        return match ($kind) {
            DestinationKind::ApnsDeviceToken => ApnsDeviceToken::of($value),
            DestinationKind::ApnsVoipToken => ApnsVoipToken::of($value),
            DestinationKind::ApnsLiveActivityToken => ApnsLiveActivityToken::of($value),
            DestinationKind::ApnsPushToStartToken => ApnsPushToStartToken::of($value),
            DestinationKind::ApnsBroadcastChannel => ApnsBroadcastChannel::of($value),
            DestinationKind::FcmToken => FcmToken::of($value),
            DestinationKind::FcmFid => FcmFid::of($value),
            DestinationKind::FcmTopic => FcmTopic::of($value),
            DestinationKind::FcmCondition => FcmCondition::of($value),
        };
    }

    /** Unix milliseconds of a datetime attribute. */
    public static function msOf(CarbonInterface $at): int
    {
        return $at->getTimestamp() * 1000 + (int) $at->format('v');
    }

    private function cipher(): TokenCipher
    {
        $cipher = app(TokenCipher::class);
        assert($cipher instanceof TokenCipher);

        return $cipher;
    }
}
