<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/** A breaker closed again after a successful probe. */
final readonly class CircuitClosed
{
    public function __construct(
        public BindingRef $binding,
        public GuardKind $guard,
        public BreakerTransition $transition,
        /** Credential generation in use when the change was observed: 0 primary, 1 secondary. */
        public int $credentialGeneration,
    ) {}
}
