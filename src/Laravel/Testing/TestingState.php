<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

/** What is switched on in one application, kept in the container so it vanishes with the application. @internal */
final class TestingState
{
    public ?PushFake $intent = null;

    public ?FakeTransportBuilder $wire = null;

    /** The environment the application really runs in, saved while a fake makes it read as production. */
    public ?string $realEnvironment = null;

    /** @var array{concrete: mixed, shared: bool}|null the QueuedSender binding to restore (null = there was none) */
    public ?array $queuedBinding = null;

    public bool $queuedSwapped = false;

    /** @var array<string, string> "scope\0name" => config hash of the fake connections seeded in the manager cache */
    public array $seeded = [];

    public bool $gateInstalled = false;
}
