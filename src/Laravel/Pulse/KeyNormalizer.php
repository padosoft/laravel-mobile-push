<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

/**
 * Turns the identifiers that may appear in a Pulse key into low-cardinality, storage-safe parts.
 *
 * Only provider, connection, environment, status, reason code, guard and transition ever reach a key. The connection is
 * host-defined (it can be a tenant name), so the distinct values seen by this process are capped: beyond the cap
 * every new connection is folded into "_other".
 */
final class KeyNormalizer
{
    public const OTHER = '_other';

    private const MAX_LENGTH = 48;

    /** @var array<string, true> */
    private array $connections = [];

    public function __construct(private readonly int $maxConnections = 50) {}

    public static function part(string $value): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._:-]+/', '_', $value);
        $clean = substr($clean, 0, self::MAX_LENGTH);

        return $clean === '' ? 'unknown' : $clean;
    }

    public function connection(string $name): string
    {
        $part = self::part($name);
        if (isset($this->connections[$part])) {
            return $part;
        }
        if (count($this->connections) >= $this->maxConnections) {
            return self::OTHER;
        }
        $this->connections[$part] = true;

        return $part;
    }

    /** @param  list<string>  $parts */
    public static function key(array $parts): string
    {
        return json_encode(array_map(self::part(...), $parts), JSON_THROW_ON_ERROR);
    }
}
