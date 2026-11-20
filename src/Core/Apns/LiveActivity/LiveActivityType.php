<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\LiveActivity;

/**
 * Explicit mapping from a PHP-side description to the Swift `ActivityAttributes` type the app declares.
 *
 * The Swift type name is never derived from a PHP class name: renaming a PHP class must not silently
 * break running Live Activities. `version()` identifies the shape of `attributes`/`content-state` so a
 * shared fixture can be checked against the app.
 */
interface LiveActivityType
{
    /** Name of the Swift structure, sent as `attributes-type` (e.g. "AdventureAttributes"). */
    public function swiftName(): string;

    /** Version of the attributes/content-state schema. Recorded in the compiled meta, not sent to the device. */
    public function version(): int;
}
