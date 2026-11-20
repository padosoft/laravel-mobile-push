<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/** What may be sent now, or why not. */
final readonly class GuardVerdict
{
    public function __construct(
        /** How many of the requested messages may be sent now (0..n). */
        public int $allowed,
        /** Set when $allowed < requested: CircuitOpen (breakers) or QuotaExceeded (gate / rate limit). */
        public ?ReasonCode $skipReason,
        /** When to try again for the part that was not allowed; null = no useful estimate. */
        public ?\DateTimeImmutable $retryAt,
        /** Credential generation to send with: 0 primary, 1 secondary. */
        public int $credentialGeneration,
        /** The allowed sends include the authentication half-open probe (one message). */
        public bool $authProbe = false,
        /** @var list<BreakerTransition> state changes that happened while checking (e.g. HalfOpened) */
        public array $transitions = [],
    ) {}

    public function skipped(): bool
    {
        return $this->allowed === 0;
    }
}
