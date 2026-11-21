<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Transport;

/**
 * Decorates the application's Transport so a fake can take over WITHOUT rebuilding the send pipeline (the manager,
 * the connection factory and the senders keep the instance they were built with). With no target set, every call
 * goes straight to the real transport: nothing is swapped unless a fake is active.
 *
 * @internal
 */
final class SwitchableTransport implements Transport
{
    private ?Transport $target = null;

    public function __construct(private readonly Transport $inner) {}

    public function route(?Transport $target): void
    {
        $this->target = $target;
    }

    public function active(): bool
    {
        return $this->target !== null;
    }

    public function send(iterable $requests, ConnectionProfile $profile): \Generator
    {
        yield from ($this->target ?? $this->inner)->send($requests, $profile);
    }

    public function close(?string $poolKey = null): void
    {
        $this->inner->close($poolKey);
        $this->target?->close($poolKey);
    }
}
