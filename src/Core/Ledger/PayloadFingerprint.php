<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

/**
 * Stable fingerprint of the canonical content of a message, used to refuse an effect key reused with another message.
 *
 * Two versions, distinguishable by their prefix:
 *  - `v2:k<key version>:<hex>` (written by the ledger): HMAC-SHA256 of the canonical JSON, keyed with a key derived
 *    from the encryption key ring (TokenCipher::digest()) and bound to the scope. A very short message (an OTP) cannot
 *    be guessed offline from the stored value, and the same message has the same fingerprint within one scope and key
 *    version. Verified with the key version it names, so a key rotation does not turn an idempotent retry into a
 *    conflict while the old key version is still in the ring;
 *  - `v1:<hex>` (older rows): unkeyed SHA-256 of the same canonical JSON; still accepted by matches().
 *
 * Canonical form:
 *  - an object becomes {"@class": FQCN, "@props": {name: value, ...}} with ALL properties (private and inherited
 *    included) sorted by name, so declaration or assignment order never matters;
 *  - a map (non-list array) is sorted by key; a list keeps its order (order is meaningful in a list);
 *  - an enum becomes {"@enum": FQCN, "case": name}; a date becomes {"@time": UTC with microseconds};
 *  - a float is tagged and printed with 17 significant digits, so 1 and 1.0 differ, as they do in JSON payloads.
 *
 * Two equal messages hash equally; any change of content, type or class changes the hash.
 */
final class PayloadFingerprint
{
    /** Prefix of the legacy, unkeyed form. */
    public const VERSION = 'v1';

    /** Prefix of the keyed form. */
    public const KEYED_VERSION = 'v2';

    private const CONTEXT = 'payload-fingerprint';

    /**
     * The legacy unkeyed form (`v1:`). Kept to verify older rows and for callers without a key ring; the ledger writes
     * keyed().
     *
     * @throws InvalidMessageException when the message holds something that is not plain data (closure, resource, cycle)
     */
    public static function of(Message $message): string
    {
        return self::VERSION.':'.hash('sha256', self::json($message));
    }

    /**
     * The keyed form (`v2:k<n>:`) for one scope, with the current key version of the cipher's key ring.
     *
     * @throws InvalidMessageException
     */
    public static function keyed(Message $message, TokenCipher $cipher, string $scope): string
    {
        return self::KEYED_VERSION.':'.$cipher->digest(self::json($message), $scope, self::CONTEXT);
    }

    /**
     * Whether a stored fingerprint (either version) is the fingerprint of this message in this scope. A keyed value
     * whose key version left the ring cannot be verified and does not match.
     *
     * @throws InvalidMessageException
     */
    public static function matches(string $stored, Message $message, TokenCipher $cipher, string $scope): bool
    {
        if (str_starts_with($stored, self::KEYED_VERSION.':')) {
            return $cipher->digestMatches(substr($stored, strlen(self::KEYED_VERSION) + 1), self::json($message), $scope, self::CONTEXT);
        }
        if (str_starts_with($stored, self::VERSION.':')) {
            return hash_equals($stored, self::of($message));
        }

        return false;
    }

    /** @throws InvalidMessageException */
    private static function json(Message $message): string
    {
        try {
            return json_encode(self::canonical($message, []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidMessageException('The message cannot be fingerprinted: it contains a string that is not valid UTF-8.');
        }
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * @param  list<object>  $path  objects being visited (cycle guard)
     */
    private static function canonical(mixed $value, array $path): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => ['@float' => sprintf('%.17g', $value)],
            is_array($value) => self::canonicalArray($value, $path),
            $value instanceof \UnitEnum => ['@enum' => $value::class, 'case' => $value->name],
            $value instanceof \DateTimeInterface => ['@time' => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u')],
            $value instanceof \Closure => throw new InvalidMessageException('A message must not contain closures.'),
            is_object($value) => self::canonicalObject($value, $path),
            default => throw new InvalidMessageException('A message must contain plain data only.'),
        };
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<object>  $path
     * @return array<mixed>
     */
    private static function canonicalArray(array $value, array $path): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonical($item, $path);
        }

        if (array_is_list($out)) {
            return $out;
        }

        ksort($out, SORT_STRING);

        // Force a JSON object even for numeric keys.
        return ['@map' => array_map(null, array_map('strval', array_keys($out)), array_values($out))];
    }

    /**
     * @param  list<object>  $path
     * @return array{"@class": string, "@props": array<string, mixed>}
     */
    private static function canonicalObject(object $value, array $path): array
    {
        if (in_array($value, $path, true)) {
            throw new InvalidMessageException('A message must not contain reference cycles.');
        }
        $path[] = $value;

        $props = [];
        for ($class = new \ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $name = $property->getDeclaringClass()->getName() === $value::class || ! $property->isPrivate()
                    ? $property->getName()
                    : $property->getDeclaringClass()->getName().'::'.$property->getName();
                if (array_key_exists($name, $props)) {
                    continue;
                }
                $props[$name] = $property->isInitialized($value)
                    ? self::canonical($property->getValue($value), $path)
                    : ['@uninitialized' => true];
            }
        }
        ksort($props, SORT_STRING);

        return ['@class' => $value::class, '@props' => $props];
    }
}
