<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/** A breaker state change together with the breaker it came from. */
final readonly class SourcedTransition
{
    public function __construct(
        public GuardSource $source,
        public BreakerTransition $transition,
    ) {}
}
