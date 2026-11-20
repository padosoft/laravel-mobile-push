<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/** A breaker state change observed by the Sender. */
final readonly class GuardTransition
{
    public function __construct(
        public BindingRef $binding,
        public GuardKind $guard,
        public BreakerTransition $transition,
        /** Credential generation in use when the change was observed. */
        public int $credentialGeneration,
    ) {}
}
