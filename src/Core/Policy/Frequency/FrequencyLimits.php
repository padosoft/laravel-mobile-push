<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * The limit table: policy bucket => limits, with a `default` entry for buckets that are not listed.
 *
 * Table form (values may be numeric strings, as they arrive from env()):
 *   ['default' => ['per_hour' => 5, 'per_day' => 20], 'marketing' => ['per_day' => 2], 'security' => 'unlimited']
 * An entry is a map with `per_hour` and/or `per_day` (positive integers), or the string `unlimited`. An empty map,
 * a zero or negative limit and unknown keys are configuration errors: a typo must not silently remove a cap.
 * Without a `default` entry, unlisted buckets are unlimited.
 */
final readonly class FrequencyLimits
{
    public const DEFAULT = 'default';

    /** @param  array<string, BucketLimits>  $buckets */
    public function __construct(private array $buckets = []) {}

    /**
     * @param  array<mixed>  $table
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $table): self
    {
        $buckets = [];
        foreach ($table as $bucket => $entry) {
            $name = (string) $bucket;
            if ($name === '') {
                throw new ConfigurationException('A frequency bucket needs a name.');
            }
            $buckets[$name] = self::parse($name, $entry);
        }

        return new self($buckets);
    }

    public function forBucket(string $bucket): BucketLimits
    {
        return $this->buckets[$bucket] ?? $this->buckets[self::DEFAULT] ?? BucketLimits::unlimited();
    }

    private static function parse(string $bucket, mixed $entry): BucketLimits
    {
        if ($entry === 'unlimited' || $entry === null) {
            return BucketLimits::unlimited();
        }
        if (! is_array($entry) || $entry === []) {
            throw new ConfigurationException("Frequency limits of '{$bucket}' must be 'unlimited' or a map with per_hour and/or per_day.");
        }
        foreach (array_keys($entry) as $key) {
            if ($key !== 'per_hour' && $key !== 'per_day') {
                throw new ConfigurationException("Frequency limits of '{$bucket}' accept only per_hour and per_day.");
            }
        }

        return BucketLimits::of(self::limit($bucket, 'per_hour', $entry['per_hour'] ?? null), self::limit($bucket, 'per_day', $entry['per_day'] ?? null));
    }

    private static function limit(string $bucket, string $key, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            throw new ConfigurationException("Frequency limit {$bucket}.{$key} must be a positive integer (use 'unlimited' to remove the cap).");
        }

        return $value;
    }
}
