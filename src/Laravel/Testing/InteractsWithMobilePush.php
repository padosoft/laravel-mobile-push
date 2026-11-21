<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\After;

/**
 * For host test cases (PHPUnit, or Pest with `uses(InteractsWithMobilePush::class)`):
 *
 *     $push = $this->fakeMobilePush();                 // intent: what did my code ask for?
 *     $wire = $this->fakeMobilePushTransport();        // bytes: what reached the provider?
 *
 * The fakes are switched off after every test.
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithMobilePush
{
    protected function fakeMobilePush(bool $allowOutsideTesting = false): PushFake
    {
        return $this->mobilePushTesting()->fake($allowOutsideTesting);
    }

    protected function fakeMobilePushTransport(bool $allowOutsideTesting = false): FakeTransportBuilder
    {
        return $this->mobilePushTesting()->fakeTransport($allowOutsideTesting);
    }

    #[After]
    protected function resetMobilePushFakes(): void
    {
        if ($this->app !== null && $this->app->resolved(TestingSession::class)) {
            $this->app->make(TestingSession::class)->reset();
        }
    }

    private function mobilePushTesting(): TestingSession
    {
        return $this->app->make(TestingSession::class);
    }
}
