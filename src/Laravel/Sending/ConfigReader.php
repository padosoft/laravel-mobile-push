<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Typed, validated reads from a config array with dotted keys. Values coming from env() arrive as strings, so
 * numeric strings and "true"/"false" are accepted. Error messages name the full key and never echo the value
 * (a misplaced secret must not end up in an exception).
 */
final readonly class ConfigReader
{
    /**
     * @param  array<mixed>  $data
     * @param  string  $prefix  printed before the key in error messages, e.g. "mobile-push.connections.ios"
     */
    public function __construct(private array $data, private string $prefix) {}

    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    public function raw(string $key): mixed
    {
        $value = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<mixed>|null */
    public function array(string $key): ?array
    {
        $value = $this->raw($key);
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw $this->error($key, 'must be an array');
        }

        return $value;
    }

    public function string(string $key): ?string
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            throw $this->error($key, 'must be a string');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function requiredString(string $key, string $remedy): string
    {
        return $this->string($key) ?? throw $this->error($key, 'is required. '.$remedy);
    }

    /** @param  list<string>  $allowed */
    public function choice(string $key, array $allowed, string $default): string
    {
        $value = $this->string($key);
        if ($value === null) {
            return $default;
        }
        $value = strtolower($value);
        if (! in_array($value, $allowed, true)) {
            throw $this->error($key, 'must be one of: '.implode(', ', $allowed));
        }

        return $value;
    }

    public function int(string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        return $this->nullableInt($key, $default, $min, $max) ?? $default;
    }

    public function nullableInt(string $key, ?int $default, ?int $min = null, ?int $max = null): ?int
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && strtolower(trim($value)) === 'null') {
            return null;
        }
        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            $value = (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (! is_int($value)) {
            throw $this->error($key, 'must be an integer'.self::range($min, $max));
        }
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw $this->error($key, 'must be an integer'.self::range($min, $max));
        }

        return $value;
    }

    public function float(string $key, float $default, float $min, float $max): float
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (! is_numeric($value)) {
            throw $this->error($key, "must be a number between {$min} and {$max}");
        }
        $value = (float) $value;
        if ($value < $min || $value > $max) {
            throw $this->error($key, "must be a number between {$min} and {$max}");
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw $this->error($key, 'must be true or false');
    }

    /**
     * A list of non-empty strings; a comma-separated string is accepted too. Null when absent.
     *
     * @return list<string>|null
     */
    public function stringList(string $key): ?array
    {
        $value = $this->raw($key);
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            throw $this->error($key, 'must be a list of names');
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw $this->error($key, 'must be a list of names');
            }
            $item = trim($item);
            if ($item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function error(string $key, string $problem): ConfigurationException
    {
        return new ConfigurationException("{$this->prefix}.{$key} {$problem}.");
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    private static function range(?int $min, ?int $max): string
    {
        return match (true) {
            $min !== null && $max !== null => " between {$min} and {$max}",
            $min !== null => " >= {$min}",
            $max !== null => " <= {$max}",
            default => '',
        };
    }
}
