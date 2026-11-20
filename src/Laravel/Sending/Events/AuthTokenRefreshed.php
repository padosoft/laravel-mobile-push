<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Identity\BindingRef;

/**
 * The provider rejected the bearer as expired and a new one was obtained. `$credentialIdentity` is the NON-secret
 * identity (team:key id, certificate fingerprint or Google principal); the token itself is never part of the event.
 */
final readonly class AuthTokenRefreshed
{
    public function __construct(
        public BindingRef $binding,
        public int $credentialGeneration,
        public string $credentialIdentity,
    ) {}
}
