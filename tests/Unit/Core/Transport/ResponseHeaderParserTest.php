<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\ResponseHeaderParser;

function transportFeedHeaders(string ...$lines): ResponseHeaderParser
{
    $parser = new ResponseHeaderParser;
    foreach ($lines as $line) {
        expect($parser->line($line))->toBe(strlen($line));
    }

    return $parser;
}

it('parses an HTTP/2 response with lowercased names', function (): void {
    $parser = transportFeedHeaders("HTTP/2 200 \r\n", "APNS-ID: 7D3B-11\r\n", "Content-Type: application/json\r\n", "\r\n");

    expect($parser->status())->toBe(200)
        ->and($parser->protocol())->toBe('2')
        ->and($parser->headers())->toBe(['apns-id' => '7D3B-11', 'content-type' => 'application/json'])
        ->and($parser->isComplete())->toBeTrue();
});

it('drops interim 1xx blocks and keeps only the final response', function (): void {
    $parser = transportFeedHeaders(
        "HTTP/1.1 100 Continue\r\n", "\r\n",
        "HTTP/1.1 103 Early Hints\r\n", "Link: </a.css>; rel=preload\r\n", "\r\n",
        "HTTP/1.1 410 Gone\r\n", "Content-Length: 25\r\n", "\r\n",
    );

    expect($parser->status())->toBe(410)
        ->and($parser->headers())->toBe(['content-length' => '25'])
        ->and($parser->isComplete())->toBeTrue();
});

it('is not complete after an interim block only', function (): void {
    $parser = transportFeedHeaders("HTTP/1.1 100 Continue\r\n", "\r\n");

    expect($parser->isComplete())->toBeFalse()->and($parser->status())->toBe(100);
});

it('drops the proxy CONNECT block when tunnelling', function (): void {
    $parser = transportFeedHeaders(
        "HTTP/1.1 200 Connection Established\r\n", "Proxy-Agent: corp-proxy\r\n", "\r\n",
        "HTTP/2 429 \r\n", "retry-after: 30\r\n", "\r\n",
    );

    expect($parser->status())->toBe(429)
        ->and($parser->protocol())->toBe('2')
        ->and($parser->headers())->toBe(['retry-after' => '30']);
});

it('joins repeated headers and trims values', function (): void {
    $parser = transportFeedHeaders("HTTP/2 200\r\n", "Vary: accept\r\n", "vary:   origin  \r\n", "x-empty:\r\n", "\r\n");

    expect($parser->headers())->toBe(['vary' => 'accept, origin', 'x-empty' => '']);
});

it('keeps colons inside values and ignores garbage lines', function (): void {
    $parser = transportFeedHeaders("HTTP/1.1 200 OK\r\n", "location: https://example.test:8443/x\r\n", "not a header line\r\n", ": no name\r\n", "\r\n");

    expect($parser->headers())->toBe(['location' => 'https://example.test:8443/x']);
});

it('ignores header lines that arrive before any status line', function (): void {
    $parser = transportFeedHeaders("x-stray: 1\r\n", "HTTP/1.0 204 No Content\r\n", "\r\n");

    expect($parser->status())->toBe(204)->and($parser->protocol())->toBe('1.0')->and($parser->headers())->toBe([]);
});
