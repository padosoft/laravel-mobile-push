<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Laravel\Sending\PushManager;

/** What is switched on in one application, kept in the container so it vanishes with the application. @internal */
final class TestingState
{
    public ?PushFake $intent = null;

    public ?FakeTransportBuilder $wire = null;

    /** The manager the active fake configured (forced execution, installed drivers, recording gate). */
    public ?PushManager $manager = null;

    public bool $queuedSwapped = false;

    /** @var array<string, array{0: string, 1: string}> slot => [scope, name] of the fake drivers installed with useDriver() */
    public array $installed = [];

    /** @var (\Closure(mixed, mixed): array<int, never>)|null the recording gate registered with eligibleUsing() */
    public ?\Closure $gate = null;

    /** @var array<mixed>|null the application's own `mobile-push.connections`, saved while the wire fake supplies test ones */
    public ?array $connectionsBackup = null;

    public bool $connectionsReplaced = false;

    /** @var list<string> names of the test connections the wire fake supplied (their cached drivers are dropped at reset) */
    public array $provided = [];

    public function active(): bool
    {
        return $this->intent !== null || $this->wire !== null;
    }

    /** Whether anything at all must be put back. */
    public function dirty(): bool
    {
        return $this->active() || $this->manager !== null || $this->queuedSwapped || $this->connectionsReplaced;
    }
}
