<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MobilePushServiceProvider::class];
    }
}
