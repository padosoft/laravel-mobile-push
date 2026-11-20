<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/** State changes caused by recording a decision, for the framework layer to turn into events. */
final readonly class GuardRecord
{
    public function __construct(
        public BreakerTransition $auth = BreakerTransition::None,
        public BreakerTransition $destination = BreakerTransition::None,
        /** Set when the decision paused the provider account (or extended the pause). */
        public ?\DateTimeImmutable $throttledUntil = null,
    ) {}
}
