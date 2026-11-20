<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * `mobile-push.live_activities.*`, read with explicit defaults.
 *
 * Apple allows up to 10,000 broadcast channels per app and environment; the local cap must stay below it.
 */
final readonly class LiveActivitiesConfig
{
    public const APPLE_CHANNEL_LIMIT = 10_000;

    public function __construct(
        /** Push connection (binding name) used when a builder does not name one. */
        public string $binding = 'apns',
        public Priority $defaultPriority = Priority::High,
        /** High-priority update attempts per activity and hour; null = unlimited. */
        public ?int $highPriorityPerHour = 20,
        public int $localExpiryHours = 8,
        /** 'start' (Apple's 8-hour rule) or 'last_update' (extend on every accepted update). */
        public string $localExpiryFrom = 'start',
        /** Retries of an update/end whose outcome was unknown (replaceable), counted per pending transition. */
        public int $maxUpdateRetries = 3,
        public int $pruneClosedAfterDays = 7,
        public int $channelCap = 9_500,
        public int $channelCleanupAfterHours = 24,
    ) {
        if ($highPriorityPerHour !== null && $highPriorityPerHour < 0) {
            throw new ConfigurationException('mobile-push.live_activities.high_priority.max_per_hour must be null or >= 0.');
        }
        if ($localExpiryHours < 1) {
            throw new ConfigurationException('mobile-push.live_activities.local_expiry.hours must be at least 1.');
        }
        if (! in_array($localExpiryFrom, ['start', 'last_update'], true)) {
            throw new ConfigurationException('mobile-push.live_activities.local_expiry.from must be "start" or "last_update".');
        }
        if ($channelCap < 1 || $channelCap >= self::APPLE_CHANNEL_LIMIT) {
            throw new ConfigurationException('mobile-push.live_activities.channels.max_per_environment must be between 1 and 9999 (Apple allows 10,000).');
        }
        if ($maxUpdateRetries < 0 || $pruneClosedAfterDays < 0 || $channelCleanupAfterHours < 0) {
            throw new ConfigurationException('mobile-push.live_activities retry, prune and cleanup values must not be negative.');
        }
    }

    public static function fromConfig(): self
    {
        $priority = config('mobile-push.live_activities.default_priority', 'high');
        $perHour = config('mobile-push.live_activities.high_priority.max_per_hour', 20);

        return new self(
            binding: self::string('mobile-push.live_activities.binding', 'apns'),
            defaultPriority: match ($priority) {
                'high', 10, '10' => Priority::High,
                'normal', 'low', 5, '5' => Priority::Normal,
                default => throw new ConfigurationException('mobile-push.live_activities.default_priority must be "high" or "normal".'),
            },
            highPriorityPerHour: $perHour === null || $perHour === '' ? null : self::int($perHour),
            localExpiryHours: self::int(config('mobile-push.live_activities.local_expiry.hours', 8)),
            localExpiryFrom: self::string('mobile-push.live_activities.local_expiry.from', 'start'),
            maxUpdateRetries: self::int(config('mobile-push.live_activities.max_update_retries', 3)),
            pruneClosedAfterDays: self::int(config('mobile-push.live_activities.prune.closed_after_days', 7)),
            channelCap: self::int(config('mobile-push.live_activities.channels.max_per_environment', 9_500)),
            channelCleanupAfterHours: self::int(config('mobile-push.live_activities.channels.cleanup_after_hours', 24)),
        );
    }

    private static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new ConfigurationException('A mobile-push.live_activities value must be an integer.');
    }
}
