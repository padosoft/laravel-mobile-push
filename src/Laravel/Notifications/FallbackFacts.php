<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Bounded summary of the results of one push effect for one notifiable: counters only, never a token.
 */
final class FallbackFacts
{
    /** @var array<string, int> */
    private array $counts = [];

    private int $total = 0;

    private bool $suppressedByPolicy = false;

    private bool $unknownAudience = false;

    public function __construct()
    {
        foreach (SendStatus::cases() as $status) {
            $this->counts[$status->value] = 0;
        }
    }

    /**
     * @param  iterable<SendResult>  $results
     */
    public static function fromResults(iterable $results): self
    {
        $facts = new self;
        foreach ($results as $result) {
            $facts->observe($result);
        }

        return $facts;
    }

    /**
     * Test/host helper: facts from counters.
     *
     * @param  array<string, int>  $counts  SendStatus value => count
     */
    public static function of(array $counts, bool $suppressedByPolicy = false, bool $unknownAudience = false): self
    {
        $facts = new self;
        foreach ($counts as $status => $count) {
            $facts->counts[SendStatus::from($status)->value] = $count;
            $facts->total += $count;
        }
        $facts->suppressedByPolicy = $suppressedByPolicy;
        $facts->unknownAudience = $unknownAudience;

        return $facts;
    }

    public function observe(SendResult $result): void
    {
        $this->counts[$result->status->value]++;
        $this->total++;
        if ($result->status === SendStatus::Skipped
            && in_array($result->reason->code, [ReasonCode::PolicyIneligible, ReasonCode::PolicyFrequencyCapped], true)) {
            $this->suppressedByPolicy = true;
        }
        if (! $result->destination->kind()->isIndividual()) {
            $this->unknownAudience = true;
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    public function count(SendStatus $status): int
    {
        return $this->counts[$status->value];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** At least one destination was skipped by consent/eligibility or a frequency cap. */
    public function suppressedByPolicy(): bool
    {
        return $this->suppressedByPolicy;
    }

    /** At least one destination is a topic, condition or broadcast channel. */
    public function unknownAudience(): bool
    {
        return $this->unknownAudience;
    }
}
