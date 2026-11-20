<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

/**
 * The result of one Request: exactly one of $response / $failure is set.
 */
final readonly class Exchange
{
    public function __construct(
        public Request $request,
        public ?Response $response,
        public ?TransportFailure $failure,
        public float $durationMs = 0.0,
    ) {
        if (($response === null) === ($failure === null)) {
            throw new \LogicException('An Exchange carries exactly one of response or failure.');
        }
    }

    public static function responded(Request $request, Response $response, float $durationMs = 0.0): self
    {
        return new self($request, $response, null, $durationMs);
    }

    public static function failed(Request $request, TransportFailure $failure, float $durationMs = 0.0): self
    {
        return new self($request, null, $failure, $durationMs);
    }

    public function hasResponse(): bool
    {
        return $this->response !== null;
    }
}
