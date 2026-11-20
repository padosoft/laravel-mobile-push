<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\TokenSlot;

/**
 * Wraps a provider token source and remembers the last bearer it handed out, so the log sinks can be told which
 * runtime secret to redact (SinkManager context key `secrets`). The token is kept in a TokenSlot: it never appears
 * in var_export(), print_r() or serialization of this object.
 */
final class TrackingTokenProvider implements AccessTokenProvider
{
    private readonly TokenSlot $last;

    public function __construct(private readonly AccessTokenProvider $inner)
    {
        $this->last = new TokenSlot;
    }

    public function token(): AuthToken
    {
        return $this->remember($this->inner->token());
    }

    public function refreshAfterRejection(AuthToken $rejected): AuthToken
    {
        return $this->remember($this->inner->refreshAfterRejection($rejected));
    }

    public function identity(): string
    {
        return $this->inner->identity();
    }

    /** The bearer value most recently handed out by this provider, if any (sensitive: for redaction only). */
    public function lastBearer(): ?string
    {
        return $this->last->get()?->value;
    }

    private function remember(AuthToken $token): AuthToken
    {
        $this->last->set($token);

        return $token;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity(), 'token' => '[redacted]'];
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A token provider cannot be serialized.');
    }
}
