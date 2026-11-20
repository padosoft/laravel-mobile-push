<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/** The credential check of one connection, as returned by CredentialMonitor. */
final readonly class CredentialFindings
{
    /** @param  list<AlertOutcome>  $alerts */
    public function __construct(
        public BindingRef $binding,
        public CheckResult $result,
        public ?int $daysRemaining,
        public ?string $error,
        public array $alerts = [],
    ) {}
}
