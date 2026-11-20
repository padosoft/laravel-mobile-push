<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/** The authentication breaker switched this credential pair to the secondary credential (decided once, for everyone). */
final readonly class CredentialFailedOver
{
    public function __construct(
        public BindingRef $binding,
        public GuardKind $guard,
        public BreakerTransition $transition,
        /** Credential generation in use when the change was observed: 0 primary, 1 secondary. */
        public int $credentialGeneration,
    ) {}
}
