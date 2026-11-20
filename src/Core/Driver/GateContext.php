<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\Message;

final readonly class GateContext
{
    public function __construct(
        public BindingRef $binding,
        public Message $message,
        public TrafficClass $trafficClass,
        public \DateTimeImmutable $now,
        public ?string $effectKey,
    ) {}
}
