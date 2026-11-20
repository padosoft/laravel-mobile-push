<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

/**
 * Whether an attempt whose outcome is unknown (the provider may have accepted it) may be sent again.
 *
 * Sending again can produce a duplicate on the device, so only messages whose later copy replaces the
 * earlier one (Live Activity updates/ends, background pushes, messages marked replaceable) are retried
 * by default. Alerts and Live Activity starts are not.
 */
enum UnknownOutcomeRetry: string
{
    case Never = 'never';
    case Replaceable = 'replaceable';
    case Always = 'always';

    public function allows(bool $replaceable): bool
    {
        return match ($this) {
            self::Never => false,
            self::Replaceable => $replaceable,
            self::Always => true,
        };
    }
}
