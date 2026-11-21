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

    public bool $queuedSwapped = false;

    /** @var array<string, array{0: string, 1: string}> slot => [scope, name] of the fake connections seeded in the manager cache */
    public array $seeded = [];

    /** @var \WeakMap<object, true> managers that already carry the recording gate */
    public \WeakMap $gated;

    /** @var array<mixed>|null the application's own `mobile-push.connections`, saved while the wire fake supplies test ones */
    public ?array $connectionsBackup = null;

    public bool $connectionsReplaced = false;

    public function __construct()
    {
        $this->gated = new \WeakMap;
    }

    public function active(): bool
    {
        return $this->intent !== null || $this->wire !== null;
    }
}
