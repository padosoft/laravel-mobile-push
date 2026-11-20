<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Scope;

/**
 * Keyed lookup index for destinations: `v<version>:` + HMAC-SHA256(key, scope || kind || value).
 *
 * The key is separate from any encryption key, so a leaked database does not allow guessing tokens offline and
 * indexes are not correlatable across systems (a plain hash would be). Fields are length-prefixed so
 * ("ab","c") and ("a","bc") can never collide.
 */
final readonly class TokenIndex
{
    public function __construct(private KeyRing $keys) {}

    /** The index to WRITE: always the current key version. */
    public function index(Destination $destination, ?string $scope = null): string
    {
        return $this->indexWith($this->keys->current, $destination, $scope);
    }

    /**
     * Every index the destination may be stored under, newest version first. Used for lookups during rotation.
     *
     * @return list<string>
     */
    public function candidates(Destination $destination, ?string $scope = null): array
    {
        return array_map(
            fn (int $version): string => $this->indexWith($version, $destination, $scope),
            $this->keys->versions(),
        );
    }

    public function matches(string $stored, Destination $destination, ?string $scope = null): bool
    {
        $found = false;

        foreach ($this->candidates($destination, $scope) as $candidate) {
            $found = hash_equals($candidate, $stored) || $found;
        }

        return $found;
    }

    /** The key version a stored index was written with, or null when it is malformed. */
    public static function versionOf(string $stored): ?int
    {
        return preg_match('/^v([1-9][0-9]{0,3}):[0-9a-f]{64}$/', $stored, $m) === 1 ? (int) $m[1] : null;
    }

    private function indexWith(int $version, Destination $destination, ?string $scope): string
    {
        $key = $this->keys->key($version) ?? throw new PrivacyException("Unknown index key version {$version}.");
        $scope = Scope::normalize($scope);
        $kind = $destination->kind()->value;
        $value = $destination->value();

        $message = pack('N', strlen($scope)).$scope.pack('N', strlen($kind)).$kind.pack('N', strlen($value)).$value;

        return 'v'.$version.':'.hash_hmac('sha256', $message, $key);
    }
}
