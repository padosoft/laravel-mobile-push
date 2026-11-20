<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

final readonly class AuthCheck
{
    public function __construct(
        public bool $allowed,
        /** This single send is the half-open probe: report its outcome. */
        public bool $probe,
        public ?\DateTimeImmutable $retryAt,
        /** Credential generation to use: 0 primary, 1 secondary. */
        public int $generation,
        public BreakerTransition $transition,
    ) {}
}
