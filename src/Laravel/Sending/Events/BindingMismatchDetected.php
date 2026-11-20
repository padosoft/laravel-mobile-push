<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Identity\BindingRef;

/**
 * A send was asked for a binding the current configuration no longer provides (the name now points to another app,
 * provider or environment, or does not resolve at all). Nothing was sent: every destination is Skipped with
 * ReasonCode::BindingMismatch, and the package never falls back to another connection.
 */
final readonly class BindingMismatchDetected
{
    public function __construct(
        public string $scope,
        public string $connection,
        /** The binding the caller captured (null when it came from a device row whose binding changed). */
        public ?BindingRef $expected,
        /** The binding the name resolves to now (null when it does not resolve). */
        public ?BindingRef $current,
        public string $reason,
    ) {}
}
