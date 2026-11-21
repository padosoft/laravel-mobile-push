<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

/**
 * Holds one in-process token so that var_export()/print_r() of the owning object cannot reveal it
 * (var_export ignores __debugInfo, but it does not look inside SplObjectStorage).
 */
final class TokenSlot
{
    /** @var \SplObjectStorage<\stdClass, AuthToken> */
    private \SplObjectStorage $storage;

    private \stdClass $anchor;

    public function __construct()
    {
        $this->storage = new \SplObjectStorage;
        $this->anchor = new \stdClass;
    }

    public function get(): ?AuthToken
    {
        $token = $this->storage[$this->anchor] ?? null;

        return $token instanceof AuthToken ? $token : null;
    }

    public function set(?AuthToken $token): void
    {
        if ($token === null) {
            $this->storage->offsetUnset($this->anchor);

            return;
        }
        $this->storage[$this->anchor] = $token;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A TokenSlot cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A TokenSlot cannot be unserialized.');
    }
}
