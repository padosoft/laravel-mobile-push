<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

/**
 * Incremental parser for the header lines libcurl hands to CURLOPT_HEADERFUNCTION.
 *
 * libcurl calls the header function once per line for EVERY header block it receives on a transfer:
 * interim 1xx responses (100 Continue, 103 Early Hints), the proxy's CONNECT reply when tunnelling,
 * redirects (never followed here), and finally the real response. Each block starts with a status line,
 * so a status line resets the parsed headers: only the last block survives, which is the final response.
 *
 * Header names are lowercased; repeated headers are joined with ", " (RFC 9110 §5.3).
 */
final class ResponseHeaderParser
{
    private ?int $status = null;

    private ?string $protocol = null;

    /** @var array<string, string> */
    private array $headers = [];

    private bool $complete = false;

    /** Feed one raw line (including its CRLF). Returns the number of bytes consumed, as libcurl expects. */
    public function line(string $raw): int
    {
        $line = rtrim($raw, "\r\n");

        if (preg_match('#^HTTP/(\d(?:\.\d)?)\s+(\d{3})(?:\s.*)?$#', $line, $m) === 1) {
            $this->protocol = $m[1];
            $this->status = (int) $m[2];
            $this->headers = [];
            $this->complete = false;

            return strlen($raw);
        }

        if ($line === '') {
            // End of a block. It is final unless it was an interim (1xx) response.
            if ($this->status !== null && ($this->status < 100 || $this->status >= 200)) {
                $this->complete = true;
            }

            return strlen($raw);
        }

        $colon = strpos($line, ':');
        if ($colon !== false && $colon > 0 && $this->status !== null) {
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name].', '.$value : $value;
        }

        return strlen($raw);
    }

    /** Status of the last block seen (null before any status line). */
    public function status(): ?int
    {
        return $this->status;
    }

    /** "1.1", "2", "3" as written on the status line libcurl synthesises. */
    public function protocol(): ?string
    {
        return $this->protocol;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** True once the blank line closing a final (non-1xx) block was seen. */
    public function isComplete(): bool
    {
        return $this->complete;
    }
}
