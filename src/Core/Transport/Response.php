<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

/**
 * A complete HTTP response. Receiving one is proof that the provider processed the request.
 */
final readonly class Response
{
    /**
     * @param  array<string, string>  $headers  lowercased header name => value
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
        public string $httpVersion = '2',
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, mixed>|null null when the body is empty or not a JSON object
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
