<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;

/**
 * For owner models (User, Team...): their activities and push-to-start tokens, and the two registrations the app
 * makes through the host's API.
 *
 * @phpstan-require-extends Model
 */
trait HasLiveActivities
{
    /** @return MorphMany<PushActivity, $this> */
    public function liveActivities(): MorphMany
    {
        /** @var MorphMany<PushActivity, $this> $relation */
        $relation = $this->morphMany(PushActivity::modelClass(), 'owner', 'owner_type', 'owner_id');

        return $relation;
    }

    /** @return MorphMany<PushToStartToken, $this> */
    public function pushToStartTokens(): MorphMany
    {
        /** @var MorphMany<PushToStartToken, $this> $relation */
        $relation = $this->morphMany(PushToStartToken::modelClass(), 'owner', 'owner_type', 'owner_id');

        return $relation;
    }

    public function registerPushToStartToken(BindingRef $binding, LiveActivityType|string $type, ApnsPushToStartToken $token, ?ApnsPushToStartToken $previous = null): PushToStartToken
    {
        return app(ActivityRegistry::class)->registerPushToStartToken($binding, $type, $token, $this, $previous);
    }

    public function reportLiveActivityToken(BindingRef $binding, LiveActivityType|string $type, ApnsLiveActivityToken $token, ActivityReport $report = new ActivityReport): PushActivity
    {
        return app(ActivityRegistry::class)->reportActivityToken($binding, $type, $token, $this, $report);
    }

    /**
     * Open activities (requested, active, ending) of this owner in exactly one scope + binding.
     *
     * @return list<PushActivity>
     */
    public function openLiveActivities(BindingRef $binding, ?string $typeKey = null): array
    {
        $query = $this->liveActivities()->getQuery()->forBinding($binding)->open();
        if ($typeKey !== null) {
            $query->where('type_key', $typeKey);
        }

        return array_values($query->orderBy('created_at')->get()->all());
    }
}
