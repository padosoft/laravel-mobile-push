<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\TestCase;

/*
 * Tests that need a Laravel application live under a "Laravel" directory.
 * Everything else is plain PHPUnit: Core must be testable without the framework.
 */
pest()->extend(TestCase::class)->in('Unit/Laravel', 'Scenario/Laravel');
