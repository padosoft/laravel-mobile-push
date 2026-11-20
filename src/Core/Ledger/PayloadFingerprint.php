<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Message\Message;

/**
 * Stable hash of the canonical content of a message: `v1:` + SHA-256 (hex) of a canonical JSON tree.
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
    public const VERSION = 'v1';

    /** @throws InvalidMessageException when the message holds something that is not plain data (closure, resource, cycle) */
    public static function of(Message $message): string
    {
        try {
            $json = json_encode(self::canonical($message, []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidMessageException('The message cannot be fingerprinted: it contains a string that is not valid UTF-8.');
        }

        return self::VERSION.':'.hash('sha256', $json);
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
