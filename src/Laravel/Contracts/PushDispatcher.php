<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Contracts;

use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendReport;

/**
 * The seam between the modules of the Laravel layer and the send pipeline. Implemented by the push manager.
 * Modules (Live Activities, campaigns, ledger relay, commands) depend on this contract, never on the manager class.
 */
interface PushDispatcher
{
    /**
     * The immutable reference of a configured or runtime-registered connection within a scope
     * (null = the current scope).
     *
     * @throws UnknownConnectionException
     */
    public function binding(string $connection, ?string $scope = null): BindingRef;

    /**
     * Send now through the full pipeline (execution mode, gates, guards, retry planning, observers).
     *
     * The binding is resolved against the CURRENT configuration for that identity. If the name now points
     * to a different identity, or the identity no longer resolves, every destination comes back as
     * Skipped with ReasonCode::BindingMismatch: there is never a fallback to a default connection.
     * The returned report is a cursor and must be consumed.
     *
     * @param  iterable<Destination|SendItem>  $destinations
     */
    public function send(BindingRef $binding, Message $message, iterable $destinations, ?SendOptions $options = null): SendReport;

    /**
     * The provider driver for a binding (connection profiles and token providers for channel management,
     * topic subscriptions, diagnostics).
     *
     * @throws UnknownConnectionException
     */
    public function driver(BindingRef $binding): Driver;

    /**
     * The send options the manager would use for this binding by default (mode, live settings, retry policy,
     * gates), so a module can adjust a copy instead of rebuilding them.
     */
    public function defaultOptions(BindingRef $binding): SendOptions;
}
