<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/**
 * Optimistic read-modify-write of a small integer map through GuardStore::compareAndSet().
 *
 * @internal
 */
final class StateCell
{
    private const MAX_ATTEMPTS = 50;

    /**
     * @template T
     *
     * @param  callable(array<string, int>): array{0: array<string, int>, 1: T}  $mutate  receives the current state (empty when absent)
     * @return T
     */
    public static function update(GuardStore $store, string $key, int $ttlSeconds, callable $mutate): mixed
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $raw = $store->get($key);
            [$next, $result] = $mutate(self::decode($raw));
            if ($next === self::decode($raw)) {
                return $result;
            }
            if ($store->compareAndSet($key, $raw, json_encode($next, JSON_THROW_ON_ERROR), $ttlSeconds)) {
                return $result;
            }
        }

        throw new \RuntimeException('Guard state is too contended to update.');
    }

    /** @return array<string, int> */
    public static function read(GuardStore $store, string $key): array
    {
        return self::decode($store->get($key));
    }

    /** @return array<string, int> */
    private static function decode(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $data = json_decode($raw, true);
        $out = [];
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_int($v)) {
                    $out[(string) $k] = $v;
                }
            }
        }

        return $out;
    }
}
