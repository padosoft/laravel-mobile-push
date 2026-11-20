<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;

/**
 * What the app sends with an activity's update token. Every field is optional; the more the app sends, the more
 * precise the match with a remote start (see ActivityRegistry::reportActivityToken()).
 */
final readonly class ActivityReport
{
    /**
     * @param  array<array-key, mixed>|null  $state  the content-state the device shows (activities started locally)
     */
    public function __construct(
        /** ActivityKit `Activity.id`. Lets the server recognise the same activity when its token rotates. */
        public ?string $clientActivityId = null,
        /** The server's activity id, when the app knows it (e.g. it was put in the attributes by the host app). */
        public ?string $activityId = null,
        /** The push-to-start token of this device for the same type: identifies the remote start it answers. */
        public ?ApnsPushToStartToken $pushToStartToken = null,
        public ?array $state = null,
        /** The broadcast channel id the activity subscribed to, if any. */
        public ?string $channelId = null,
        public ?\DateTimeInterface $startedAt = null,
    ) {
        if ($clientActivityId !== null && (trim($clientActivityId) === '' || strlen($clientActivityId) > 128)) {
            throw new \InvalidArgumentException('The client activity id must be 1 to 128 characters.');
        }
    }
}
