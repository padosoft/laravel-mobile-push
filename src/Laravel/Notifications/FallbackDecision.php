<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

final readonly class FallbackDecision
{
    public function __construct(public bool $fallback, public FallbackReason $reason) {}
}
