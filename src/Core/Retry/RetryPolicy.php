<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Retry settings shared by every component that may cause a new attempt (transport, sender, queue job,
 * credential failover). They all go through RetryPlanner, so they all consume the same budget.
 */
final readonly class RetryPolicy
{
    /**
     * @throws ConfigurationException when a value is out of range (detected before the network)
     */
    public function __construct(
        /** Total attempts per destination and effect, the first one included. */
        public int $maxAttempts = 5,
        /** Longest time from the first attempt to the last planned one. */
        public int $maxElapsedSeconds = 3600,
        /** Delay before the first retry; doubled at every further retry. */
        public int $backoffBaseMs = 1000,
        /** Cap of the exponential part (a provider minimum may still exceed it). */
        public int $backoffMaxMs = 300_000,
        /** Fraction of random spread, 0 = deterministic, 1 = up to 100 %. */
        public float $jitter = 0.2,
        public UnknownOutcomeRetry $unknownOutcome = UnknownOutcomeRetry::Replaceable,
        /**
         * Longest wait the caller may do in-process before the next attempt; anything longer is returned as
         * Deferred to be re-queued. 0 = only immediate retries (e.g. right after a token refresh) run inline.
         */
        public int $inlineThresholdMs = 0,
    ) {
        if ($maxAttempts < 1) {
            throw new ConfigurationException('retry.max_attempts must be at least 1.');
        }
        if ($maxElapsedSeconds < 0) {
            throw new ConfigurationException('retry.max_elapsed must not be negative.');
        }
        if ($backoffBaseMs < 0 || $backoffMaxMs < $backoffBaseMs) {
            throw new ConfigurationException('retry.backoff requires 0 <= base <= max.');
        }
        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new ConfigurationException('retry.jitter must be between 0 and 1.');
        }
        if ($inlineThresholdMs < 0) {
            throw new ConfigurationException('retry.inline_threshold must not be negative.');
        }
    }
}
