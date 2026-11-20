<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

/**
 * The single classification of one exchange. Produced by OutcomeClassifier, consumed by the RetryPlanner,
 * the breakers and the listeners. Nothing else interprets provider responses.
 */
final readonly class Decision
{
    public function __construct(
        /**
         * The status if no further attempt is made: Accepted, Validated, Rejected (provider refused),
         * Failed (known not accepted: provider unavailable/unreachable) or OutcomeUnknown.
         * Deferred and Expired are assigned later by the RetryPlanner.
         */
        public SendStatus $status,
        public Reason $reason,
        /** Whether another attempt is allowed at all (still subject to the retry budget and unknown-outcome policy). */
        public bool $retryable = false,
        /** Minimum delay before the next attempt, from the provider guidance or Retry-After. */
        public ?int $minDelaySeconds = null,
        public DestinationAction $destinationAction = DestinationAction::None,
        public CredentialAction $credentialAction = CredentialAction::None,
        /** Signals a provider-wide slowdown (quota, 503) for the shared throttle gate. */
        public bool $throttleProvider = false,
        /** APNs 410 "timestamp" (Unix milliseconds): the registration was invalid as of this instant. */
        public ?int $invalidatedAtMs = null,
        public ?int $httpStatus = null,
        public ?string $providerMessageId = null,
        /** @var array<string, string> extra provider metadata, e.g. apns-unique-id */
        public array $meta = [],
    ) {}
}
