<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;

/**
 * An APNs broadcast channel of one app binding and environment. Written only by ChannelRegistry. The channel id is
 * not a secret (every subscribed device knows it) and is stored in clear.
 *
 * @property string $id
 * @property string $scope
 * @property string $binding_name
 * @property string $binding_identity
 * @property string $app_id
 * @property Environment $environment
 * @property string|null $channel_id
 * @property StoragePolicy $storage_policy
 * @property string|null $label
 * @property string|null $type_key
 * @property ChannelState $state
 * @property CloseReason|null $closed_reason
 * @property string|null $create_request_id
 * @property CarbonInterface|null $state_changed_at
 * @property array<array-key, mixed>|null $last_content_state
 * @property string|null $last_event
 * @property string|null $last_status
 * @property int|null $last_priority
 * @property int|null $last_timestamp
 * @property CarbonInterface|null $last_published_at
 * @property int $publications
 * @property CarbonInterface|null $ended_at
 * @property int $dispatch_seq
 * @property int $applied_seq
 * @property int|null $pending_seq
 * @property array<array-key, mixed>|null $pending_payload
 * @property int|null $pending_due_at
 * @property int $pending_attempts
 * @property int $version
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BroadcastChannel extends Model
{
    use ConfiguredTable;
    use HasUlids;

    /** @var list<string> */
    protected $guarded = [];

    protected static function tableConfig(): array
    {
        return ['mobile-push.live_activities.channels_table', 'mobile_push_broadcast_channels', 'mobile-push.live_activities.models.broadcast_channel'];
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'environment' => Environment::class,
            'closed_reason' => CloseReason::class,
            'storage_policy' => StoragePolicy::class,
            'state' => ChannelState::class,
            'last_content_state' => 'array',
            'pending_payload' => 'array',
            'last_priority' => 'integer',
            'last_timestamp' => 'integer',
            'publications' => 'integer',
            'dispatch_seq' => 'integer',
            'applied_seq' => 'integer',
            'pending_seq' => 'integer',
            'pending_due_at' => 'integer',
            'pending_attempts' => 'integer',
            'version' => 'integer',
            'state_changed_at' => 'datetime',
            'last_published_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BroadcastChannel>  $query
     * @return Builder<BroadcastChannel>
     */
    public function scopeForBinding(Builder $query, BindingRef $binding): Builder
    {
        return $query->where('scope', $binding->scope)->where('binding_identity', $binding->identity());
    }

    /** The typed destination of an active channel, or null when APNs never gave (or no longer has) an id. */
    public function destination(): ?ApnsBroadcastChannel
    {
        if ($this->channel_id === null || $this->state !== ChannelState::Active) {
            return null;
        }

        return ApnsBroadcastChannel::of($this->channel_id)->withRef(new DestinationRef((string) $this->id));
    }
}
