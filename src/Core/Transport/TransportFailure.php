<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

final readonly class TransportFailure
{
    public function __construct(
        public TransportEvidence $evidence,
        public int $errno,
        public string $message,
        /** True when at least part of the request was written to the wire. */
        public bool $requestWritten = true,
    ) {}
}
