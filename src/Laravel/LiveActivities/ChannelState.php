<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

enum ChannelState: string
{
    /** The row exists, the create request is being made. */
    case Creating = 'creating';
    case Active = 'active';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    /** The create's outcome was lost: a channel may exist at APNs. Resolved only by ChannelRegistry::sync(), never by a blind retry. */
    case Unknown = 'unknown';

    /**
     * States that may hold (or may soon hold) a channel at APNs: they count against the local cap.
     *
     * @return list<string>
     */
    public static function counted(): array
    {
        return [self::Creating->value, self::Active->value, self::Deleting->value, self::Unknown->value];
    }
}
