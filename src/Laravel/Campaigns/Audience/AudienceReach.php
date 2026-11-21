<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

enum AudienceReach: string
{
    /** Every destination is one installation: delivery counts are recipient counts. */
    case Individual = 'individual';

    /** Topics, conditions, broadcast channels: one delivery is one publication; recipients are unknown. */
    case FanOut = 'fan_out';

    /** Both, or not known before reading (an import file). Recipient numbers cover the individual part only. */
    case Mixed = 'mixed';

    public function recipientsKnown(): bool
    {
        return $this === self::Individual;
    }
}
