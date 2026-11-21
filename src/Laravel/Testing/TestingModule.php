<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Module;

/**
 * Testing support (MobilePush::fake(), MobilePush::fakeTransport(), InteractsWithMobilePush).
 *
 * Nothing is swapped unless a fake is activated: the only thing registered up front is a pass-through decorator
 * around the Transport (no behaviour of its own) and the session object that switches fakes on and off.
 * Activating a fake outside the `testing` environment throws FakeNotAllowedException unless
 * `mobile-push.testing.allow_fakes` is true or the call says so explicitly.
 */
final class TestingModule implements Module
{
    public static function register(Application $app): void
    {
        $app->singleton(TestingSession::class, static fn (Application $app): TestingSession => new TestingSession($app));

        $app->extend(Transport::class, static fn (Transport $transport): Transport => $transport instanceof SwitchableTransport ? $transport : new SwitchableTransport($transport));
    }

    public static function boot(Application $app): void {}
}
