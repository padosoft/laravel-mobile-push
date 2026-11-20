<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/** A breaker opened (or reopened after a failed probe): sends on this binding are skipped until it half-opens. */
final readonly class CircuitOpened
{
    public function __construct(
        public BindingRef $binding,
        public GuardKind $guard,
        public BreakerTransition $transition,
        /** Credential generation in use when the change was observed: 0 primary, 1 secondary. */
        public int $credentialGeneration,
    ) {}
}
