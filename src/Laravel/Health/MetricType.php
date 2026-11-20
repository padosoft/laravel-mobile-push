<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
}
