<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;

/**
 * The execution-mode decision for channel management of one binding (OperationKind::ChannelManagement), with the
 * binding's default send options: ChannelRegistry asks it BEFORE writing any local state, so a simulated or blocked
 * create / delete / sync leaves the table exactly as it was.
 */
final readonly class ChannelManagementGate
{
    public function __construct(
        private PushDispatcher $dispatcher,
        private TokenIndex $index,
    ) {}

    public function decide(BindingRef $binding): SafeModeDecision
    {
        $options = $this->dispatcher->defaultOptions($binding);

        return (new SafeModeGuard($this->index))->decide(
            $options->effectiveMode,
            $options->applicationEnvironment ?? '',
            $binding,
            null,
            OperationKind::ChannelManagement,
            $options->live,
        );
    }
}
