<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

/**
 * A set of versioned secret keys with one current version. Keys never appear in dumps or exceptions.
 */
final readonly class KeyRing
{
    public const MIN_KEY_BYTES = 32;

    /** @var array<int, string> */
    private array $keys;

    /** Version used for new writes. */
    public int $current;

    /**
     * @param  array<int, string>  $keys  version (>= 1) => raw key (at least 32 bytes)
     * @param  int  $current  version used for new writes; 0 means the highest version
     */
    public function __construct(
        #[\SensitiveParameter] array $keys,
        int $current = 0,
    ) {
        if ($keys === []) {
            throw new PrivacyException('At least one key version is required.');
        }

        foreach ($keys as $version => $key) {
            if ($version < 1 || $version > 9999) {
                throw new PrivacyException('Key versions must be integers between 1 and 9999.');
            }

            if (strlen($key) < self::MIN_KEY_BYTES) {
                throw new PrivacyException("Key version {$version} is shorter than ".self::MIN_KEY_BYTES.' bytes.');
            }
        }

        $this->keys = $keys;
        $current = $current === 0 ? max(array_keys($keys)) : $current;

        if (! isset($keys[$current])) {
            throw new PrivacyException("The current key version {$current} is not in the key ring.");
        }

        $this->current = $current;
    }

    public function key(int $version): ?string
    {
        return $this->keys[$version] ?? null;
    }

    /** @return list<int> versions, newest first */
    public function versions(): array
    {
        $versions = array_keys($this->keys);
        rsort($versions);

        return $versions;
    }

    /** @return array{versions: list<int>, current: int} */
    public function __debugInfo(): array
    {
        return ['versions' => $this->versions(), 'current' => $this->current];
    }
}
