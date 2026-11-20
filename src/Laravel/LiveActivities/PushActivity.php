<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

/**
 * One Live Activity on one device. Written only by ActivityRegistry.
 *
 * Three facts about its end are kept apart: `local_expires_at` (past it the server stops sending, state
 * `expired`), `end_accepted_at` (APNs accepted an end) and `client_reported_state` (what the app says the device
 * shows; unknown to the server unless the app reports it).
 *
 * @property string $id
 * @property string $scope
 * @property string $binding_name
 * @property string $binding_identity
 * @property string $app_id
 * @property Environment $environment
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $type_key
 * @property string $swift_name
 * @property int $type_version
 * @property string|null $client_activity_id
 * @property string|null $push_to_start_id
 * @property string|null $update_token
 * @property string|null $update_token_index
 * @property int $token_generation
 * @property TokenState|null $token_state
 * @property string|null $broadcast_channel_id
 * @property ActivityState $state
 * @property string|null $start_status
 * @property CloseReason|null $closed_reason
 * @property array<array-key, mixed>|null $last_content_state
 * @property string|null $last_event
 * @property string|null $last_status
 * @property int|null $last_priority
 * @property int|null $last_timestamp
 * @property CarbonInterface|null $last_sent_at
 * @property CarbonInterface|null $last_accepted_at
 * @property int $dispatch_seq
 * @property int $applied_seq
 * @property int|null $pending_seq
 * @property array<array-key, mixed>|null $pending_payload
 * @property int|null $pending_due_at
 * @property int $pending_attempts
 * @property int|null $hp_window_started_at
 * @property int $hp_count
 * @property int $version
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $stale_at
 * @property CarbonInterface|null $local_expires_at
 * @property CarbonInterface|null $end_requested_at
 * @property CarbonInterface|null $end_accepted_at
 * @property CarbonInterface|null $dismissal_at
 * @property CarbonInterface|null $closed_at
 * @property ClientActivityState|null $client_reported_state
 * @property CarbonInterface|null $client_reported_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PushActivity extends Model
{
    use ConfiguredTable;
    use HasUlids;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['update_token', 'update_token_index'];

    protected static function tableConfig(): array
    {
        return ['mobile-push.live_activities.table', 'mobile_push_live_activities', 'mobile-push.live_activities.models.activity'];
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'environment' => Environment::class,
            'closed_reason' => CloseReason::class,
            'state' => ActivityState::class,
            'token_state' => TokenState::class,
            'client_reported_state' => ClientActivityState::class,
            'last_content_state' => 'array',
            'pending_payload' => 'array',
            'type_version' => 'integer',
            'token_generation' => 'integer',
            'last_priority' => 'integer',
            'last_timestamp' => 'integer',
            'dispatch_seq' => 'integer',
            'applied_seq' => 'integer',
            'pending_seq' => 'integer',
            'pending_due_at' => 'integer',
            'pending_attempts' => 'integer',
            'hp_window_started_at' => 'integer',
            'hp_count' => 'integer',
            'version' => 'integer',
            'last_sent_at' => 'datetime',
            'last_accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'stale_at' => 'datetime',
            'local_expires_at' => 'datetime',
            'end_requested_at' => 'datetime',
            'end_accepted_at' => 'datetime',
            'dismissal_at' => 'datetime',
            'closed_at' => 'datetime',
            'client_reported_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /**
     * @param  Builder<PushActivity>  $query
     * @return Builder<PushActivity>
     */
    public function scopeForBinding(Builder $query, BindingRef $binding): Builder
    {
        return $query->where('scope', $binding->scope)->where('binding_identity', $binding->identity());
    }

    /**
     * @param  Builder<PushActivity>  $query
     * @return Builder<PushActivity>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', ActivityState::open());
    }

    public function ref(): DestinationRef
    {
        return new DestinationRef((string) $this->id, $this->token_generation, $this->owner_type, $this->owner_id);
    }

    public function hasUsableToken(): bool
    {
        return $this->update_token !== null && $this->token_state === TokenState::Active;
    }

    /** The update token of this activity (decrypted) carrying its DestinationRef, or null when none was reported. */
    public function destination(): ?ApnsLiveActivityToken
    {
        if ($this->update_token === null) {
            return null;
        }

        return ApnsLiveActivityToken::of(app(TokenCipher::class)->decrypt($this->update_token, $this->scope, DestinationKind::ApnsLiveActivityToken->value))
            ->withRef($this->ref());
    }
}
