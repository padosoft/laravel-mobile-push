<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel;

use Illuminate\Contracts\Foundation\Application;

/**
 * A self-contained slice of the Laravel layer (cache adapters, devices, ledger, ...).
 *
 * MobilePushServiceProvider calls every module listed in its MODULES constant. A module registers its own
 * container bindings and boots its own migrations/commands/listeners, so parallel work on different
 * modules never needs to edit the service provider.
 */
interface Module
{
    /** Container bindings only. Must not resolve anything. */
    public static function register(Application $app): void;

    /** Publishing, migrations, commands, event listeners. Honour the module's "enabled" config flag here. */
    public static function boot(Application $app): void;
}
