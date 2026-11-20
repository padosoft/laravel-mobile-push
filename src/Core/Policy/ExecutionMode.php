<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/** The effective execution mode: whether the package may touch a provider network at all. */
enum ExecutionMode: string
{
    case Live = 'live';
    case Simulate = 'simulate';
}
