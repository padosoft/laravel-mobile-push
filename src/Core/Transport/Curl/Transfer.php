<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Transport\Request;

/**
 * Mutable state of one in-flight request.
 *
 * @internal
 */
final class Transfer
{
    public readonly ResponseHeaderParser $headers;

    public string $responseBody = '';

    /** Header + body bytes received, over every attempt libcurl made. */
    public int $responseBytes = 0;

    /** Request body bytes handed to libcurl by our read callback, over every attempt. */
    public int $bodyRead = 0;

    public int $bodyOffset = 0;

    public bool $bodyTooLarge = false;

    public function __construct(
        public readonly Request $request,
        public readonly \CurlHandle $handle,
        public readonly float $startedAt,
    ) {
        $this->headers = new ResponseHeaderParser;
    }
}
