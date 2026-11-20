<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Outcome;

/**
 * Which minimum delay applies before retrying an FCM answer.
 */
enum FcmDelay: string
{
    case None = 'none';
    /** Honour Retry-After when present, no minimum otherwise. */
    case RetryAfter = 'retry_after';
    /** max(FcmOutcomeClassifier::$throttledMinimumDelaySeconds (60), Retry-After). */
    case Throttled = 'throttled';
}
