<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/** What FallbackEvaluator did. */
final readonly class FallbackOutcome
{
    /**
     * @param  list<string>  $dispatched  channels the notification was sent through
     * @param  array<string, string>  $withheld  channel => "policy_denied" | "already_sent"
     */
    public function __construct(
        public FallbackDecision $decision,
        public array $dispatched = [],
        public array $withheld = [],
    ) {}
}
