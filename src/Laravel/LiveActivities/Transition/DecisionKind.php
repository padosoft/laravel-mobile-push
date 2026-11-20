<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

enum DecisionKind: string
{
    case Send = 'send';
    /** Keep the transition as the row's pending one (latest wins) and send it from `$dueAt`. */
    case Defer = 'defer';
    case Skip = 'skip';
}
