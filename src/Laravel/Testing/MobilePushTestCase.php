<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Illuminate\Foundation\Testing\TestCase;

/**
 * Optional base class for a host test case: the Laravel test case with InteractsWithMobilePush already applied.
 * Prefer the trait when your tests already extend your own base class.
 */
abstract class MobilePushTestCase extends TestCase
{
    use InteractsWithMobilePush;
}
