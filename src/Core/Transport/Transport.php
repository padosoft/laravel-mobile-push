<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

use Padosoft\MobilePush\Core\Exceptions\TransportConfigurationException;

interface Transport
{
    /**
     * Send requests and yield one Exchange per request as each completes (order is not guaranteed).
     *
     * Contract:
     *  - every request yields exactly one Exchange, keyed by Request::$id: no request may disappear;
     *  - a per-request failure is an Exchange with a TransportFailure, never an exception;
     *  - TransportEvidence::NotSent is reported only with proof; anything else is Unknown;
     *  - the input is consumed lazily so memory stays flat for very large batches.
     *
     * @param  iterable<Request>  $requests
     * @return \Generator<string, Exchange>
     *
     * @throws TransportConfigurationException before any request is sent (e.g. HTTP/2 unavailable)
     */
    public function send(iterable $requests, ConnectionProfile $profile): \Generator;

    /**
     * Close the pool for one key, or every pool when null.
     */
    public function close(?string $poolKey = null): void;
}
