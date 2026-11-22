<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

/**
 * Per-person frequency cap, decided once per logical effect.
 *
 * Per window the individual destinations are grouped by subject (the owner, or the destination itself when it has
 * none) and ONE decision is taken per subject for (scope, effect key, bucket): all devices of a user are allowed or
 * all are capped, a retry or a continuation gets the stored decision and consumes nothing. Topics, conditions and
 * broadcast channels are never capped. `bypassFrequencyCap` skips the cap; it has nothing to do with consent.
 *
 * Capped destinations are Skipped with PolicyFrequencyCapped (never an error, never retried). The gate never
 * throws: when the store is unavailable it fails open or closed according to `$onStoreFailure`.
 *
 * Without an effect key (GateContext::$effectKey null) there is no stable identity: each call is its own effect, so
 * a retry of an un-keyed send may consume again, and so may two windows of one big un-keyed send.
 *
 * A message that is not a PushMessage or has no policy uses `$noPolicyBucket` (null = not capped).
 */
final readonly class FrequencyCapGate implements DestinationGate
{
    /** How long a destination waits when its counter was too contended to update. */
    public const CONTENDED_RETRY_SECONDS = 5;

    public function __construct(
        private FrequencyCounter $counter,
        private FrequencyLimits $limits,
        private TokenIndex $index,
        private StoreFailurePolicy $onStoreFailure = StoreFailurePolicy::Allow,
        private ?string $noPolicyBucket = FrequencyLimits::DEFAULT,
    ) {}

    public function check(GateContext $context, array $items): array
    {
        try {
            return $this->evaluate($context, $items);
        } catch (\Throwable) {
            return $this->failure($items);
        }
    }

    /**
     * @param  list<SendItem>  $items
     * @return array<int, GateDenial>
     */
    private function evaluate(GateContext $context, array $items): array
    {
        $message = $context->message;
        if ($message instanceof PushMessage && $message->bypassFrequencyCap) {
            return [];
        }
        $bucket = $message instanceof PushMessage && $message->policy !== null && trim($message->policy) !== ''
            ? trim($message->policy)
            : $this->noPolicyBucket;
        if ($bucket === null) {
            return [];
        }
        $limits = $this->limits->forBucket($bucket);
        if ($limits->isUnlimited()) {
            return [];
        }

        $scope = $context->binding->scope;
        /** @var array<string, list<int>> $groups subject => positions */
        $groups = [];
        foreach ($items as $position => $item) {
            if (! $item->destination->kind()->isIndividual()) {
                continue;
            }
            try {
                $subject = Subject::forDestination($item->destination, $this->index, $scope);
            } catch (\Throwable) {
                $groups['!'.$position][] = $position; // an unindexable destination is its own failing subject

                continue;
            }
            $groups[$subject][] = $position;
        }

        $denials = [];
        foreach ($groups as $subject => $positions) {
            $subject = (string) $subject;
            try {
                if (str_starts_with($subject, '!')) {
                    throw new FrequencyStoreUnavailable('No subject.');
                }
                $decision = $this->counter->decide($scope, $subject, $bucket, $limits, $context->effectKey);
            } catch (FrequencyStoreContended) {
                // The store is alive and the cap could not be checked: sending now could exceed it. Not a store
                // failure, so the store-failure policy does not apply: the destination waits a few seconds.
                $retryAt = $context->now->add(new \DateInterval('PT'.self::CONTENDED_RETRY_SECONDS.'S'));
                foreach ($positions as $position) {
                    $denials[$position] = GateDenial::defer(ReasonCode::QuotaExceeded, $retryAt, 'Frequency counters are too contended to update; the cap could not be checked, so the destination is retried shortly.');
                }

                continue;
            } catch (\Throwable) {
                foreach ($positions as $position) {
                    $denial = $this->failureDenial();
                    if ($denial !== null) {
                        $denials[$position] = $denial;
                    }
                }

                continue;
            }
            if ($decision->allowed) {
                continue;
            }
            $hint = 'Frequency cap for policy "'.$bucket.'": at most '.$decision->describeLimit().'.';
            foreach ($positions as $position) {
                $denials[$position] = GateDenial::skip(ReasonCode::PolicyFrequencyCapped, $hint);
            }
        }
        ksort($denials);

        return $denials;
    }

    /**
     * @param  list<SendItem>  $items
     * @return array<int, GateDenial>
     */
    private function failure(array $items): array
    {
        $denial = $this->failureDenial();
        if ($denial === null) {
            return [];
        }
        $denials = [];
        foreach ($items as $position => $item) {
            if ($item->destination->kind()->isIndividual()) {
                $denials[$position] = $denial;
            }
        }

        return $denials;
    }

    private function failureDenial(): ?GateDenial
    {
        return $this->onStoreFailure === StoreFailurePolicy::Deny
            ? GateDenial::skip(ReasonCode::PolicyFrequencyCapped, 'Frequency counters unavailable; failing closed.')
            : null;
    }
}
