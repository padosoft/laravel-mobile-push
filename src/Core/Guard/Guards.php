<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Psr\Clock\ClockInterface;

/** The single entry point the sender calls before and after talking to a provider. */
final class Guards
{
    public const MIN_THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ThrottleGate $gate,
        private readonly AuthBreaker $auth,
        private readonly DestinationQualityBreaker $quality,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Order: authentication breaker, throttle gate, destination quality (bulk only), then rate limit.
     * Permits are consumed only for the messages that passed every earlier check.
     *
     * @param  string  $bindingIdentity  BindingRef::identity()
     * @param  string  $credentialIdentity  stable id of the credential (same for primary and secondary)
     * @param  string  $accountKey  AccountKey::of(...)
     */
    public function before(string $bindingIdentity, string $credentialIdentity, string $accountKey, TrafficClass $class, int $n): GuardVerdict
    {
        $transitions = [];

        $check = $this->auth->check($credentialIdentity);
        if ($check->transition !== BreakerTransition::None) {
            $transitions[] = $check->transition;
        }
        if (! $check->allowed) {
            return new GuardVerdict(0, ReasonCode::CircuitOpen, $check->retryAt, $check->generation, false, $transitions);
        }
        $wanted = $check->probe ? min($n, 1) : $n;

        $until = $this->gate->pausedUntil($accountKey);
        if ($until !== null) {
            return new GuardVerdict(0, ReasonCode::QuotaExceeded, $until, $check->generation, false, $transitions);
        }

        [$granted, $transition, $retryAt] = $this->quality->allow($bindingIdentity, $wanted, $class);
        if ($transition !== BreakerTransition::None) {
            $transitions[] = $transition;
        }
        if ($granted === 0) {
            return new GuardVerdict(0, ReasonCode::CircuitOpen, $retryAt, $check->generation, false, $transitions);
        }

        $permits = $this->limiter->acquire($accountKey, $granted, $class);
        if ($permits === 0) {
            return new GuardVerdict(0, ReasonCode::QuotaExceeded, $this->limiter->availableAt($accountKey, 1, $class), $check->generation, false, $transitions);
        }

        $partial = $permits < $n;

        return new GuardVerdict(
            $permits,
            $partial ? ($permits < $granted ? ReasonCode::QuotaExceeded : ReasonCode::CircuitOpen) : null,
            $partial ? ($permits < $granted ? $this->limiter->availableAt($accountKey, 1, $class) : $retryAt ?? $check->retryAt) : null,
            $check->generation,
            $check->probe,
            $transitions,
        );
    }

    /**
     * Feeds all guards from one classification. $count > 1 records the same outcome for several messages
     * (used for aggregated batch results).
     */
    public function record(
        Decision $decision,
        string $bindingIdentity,
        string $credentialIdentity,
        string $accountKey,
        int $credentialGeneration = 0,
        bool $hasSecondary = false,
        int $count = 1,
    ): GuardRecord {
        $authTransition = BreakerTransition::None;
        if ($decision->credentialAction === CredentialAction::ReportAuthFailure) {
            $authTransition = $this->auth->recordFailure($credentialIdentity, $credentialGeneration, $hasSecondary);
        } elseif ($decision->credentialAction === CredentialAction::None
            && in_array($decision->status, [SendStatus::Accepted, SendStatus::Validated], true)) {
            $authTransition = $this->auth->recordSuccess($credentialIdentity, $credentialGeneration);
        }

        $until = null;
        if ($decision->throttleProvider) {
            $seconds = max(self::MIN_THROTTLE_SECONDS, $decision->minDelaySeconds ?? 0);
            $until = $this->gate->pause($accountKey, $this->clock->now()->modify(sprintf('+%d seconds', $seconds)));
        }

        $destination = BreakerTransition::None;
        $kind = DestinationQualityBreaker::classify($decision);
        if ($kind !== null) {
            $destination = $this->quality->record($bindingIdentity, $count, $kind * $count);
        }

        return new GuardRecord($authTransition, $destination, $until);
    }
}
