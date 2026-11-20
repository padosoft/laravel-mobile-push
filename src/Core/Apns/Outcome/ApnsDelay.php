<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Outcome;

/**
 * Which minimum delay applies before retrying an APNs answer (the values are classifier settings).
 */
enum ApnsDelay: string
{
    /** No provider minimum: the planner's backoff alone decides. */
    case None = 'none';
    /** 5xx: Apple says to retry after 15 minutes (ApnsOutcomeClassifier::$serverErrorRetryDelaySeconds). */
    case ServerError = 'server_error';
    /** 429 TooManyRequests on one device token: "retry with a delay" (no figure given by Apple). */
    case DeviceRateLimit = 'device_rate_limit';
}
