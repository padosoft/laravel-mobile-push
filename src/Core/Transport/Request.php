<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

/**
 * One outbound HTTP request. `$id` correlates the request with its Exchange and must be unique within a send() call.
 */
final readonly class Request
{
    /**
     * @param  array<string, string>  $headers  header name => value (names are sent lowercased)
     */
    public function __construct(
        public string $id,
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
        public ?int $timeoutMs = null,
    ) {}
}
