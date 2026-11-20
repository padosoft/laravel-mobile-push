<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Contracts;

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\Message;

/**
 * Queues a send instead of running it inline.
 *
 * Light profile: a best-effort job that carries a small, encrypted list of destinations.
 * Durable profile: the intent is written to the ledger (inside the caller's transaction when there is one)
 * and a relay job carries only ids.
 */
interface QueuedSender
{
    /**
     * @param  iterable<Destination>  $destinations
     * @return string the effect id of the queued send
     */
    public function queue(BindingRef $binding, Message $message, iterable $destinations, SendOptions $options, QueueOptions $queue): string;
}
