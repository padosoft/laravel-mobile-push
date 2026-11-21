<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Guard\TrafficClass;

/** The per-send options the application chose, as the fake saw them. */
final readonly class SentOptions
{
    public function __construct(
        public bool $validateOnly = false,
        public TrafficClass $trafficClass = TrafficClass::Transactional,
        public ?string $effectKey = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}
}
