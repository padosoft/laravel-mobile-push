<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

/**
 * A push-to-start token (iOS 17.2+) of one device, for one app binding and one Swift attributes type.
 * Written only by ActivityRegistry.
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
 * @property string $token
 * @property string $token_index
 * @property int $generation
 * @property TokenState $state
 * @property string|null $state_reason
 * @property CarbonInterface|null $state_changed_at
 * @property CarbonInterface $registered_at
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PushToStartToken extends Model
{
    use ConfiguredTable;
    use HasUlids;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token', 'token_index'];

    protected static function tableConfig(): array
    {
        return ['mobile-push.live_activities.push_to_start_table', 'mobile_push_live_activity_push_to_start_tokens', 'mobile-push.live_activities.models.push_to_start_token'];
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'environment' => Environment::class,
            'state' => TokenState::class,
            'generation' => 'integer',
            'state_changed_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /**
     * @param  Builder<PushToStartToken>  $query
     * @return Builder<PushToStartToken>
     */
    public function scopeForBinding(Builder $query, BindingRef $binding): Builder
    {
        return $query->where('scope', $binding->scope)->where('binding_identity', $binding->identity());
    }

    public function ref(): DestinationRef
    {
        return new DestinationRef((string) $this->id, $this->generation, $this->owner_type, $this->owner_id);
    }

    /** The typed destination (decrypted) carrying its DestinationRef. */
    public function destination(): ApnsPushToStartToken
    {
        return ApnsPushToStartToken::of(app(TokenCipher::class)->decrypt($this->token, $this->scope, DestinationKind::ApnsPushToStartToken->value))
            ->withRef($this->ref());
    }
}
