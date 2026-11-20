<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

/**
 * Tenant boundary. Every cache key, lock, dedupe key, receipt, export/forget query and channel action is scoped.
 * A scope is never derived from client-supplied payload.
 */
final readonly class Scope
{
    public const DEFAULT = 'default';

    public static function normalize(?string $scope): string
    {
        $scope = trim((string) $scope);

        if ($scope === '') {
            return self::DEFAULT;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $scope) !== 1) {
            throw new \InvalidArgumentException('A scope may contain only letters, digits, ".", "_", ":" and "-" (max 64 chars).');
        }

        return $scope;
    }
}
