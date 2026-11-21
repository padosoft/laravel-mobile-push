<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Transport;

/**
 * The real CurlTransport (with its own pool registry, so the test can look at it) pointed at the local HTTP/2 mock
 * server. The package has no endpoint override in its Laravel config (only official hosts in production), so this
 * decorator rewrites the origin of each request just before the real transport: pool keys, profiles, windows,
 * pooling and connection reuse are exactly the package's own.
 */
final class RedirectingTransport implements Transport
{
    public function __construct(public readonly CurlTransport $inner, private readonly string $origin) {}

    public function send(iterable $requests, ConnectionProfile $profile): \Generator
    {
        $originals = [];
        $redirected = (function () use ($requests, &$originals): \Generator {
            foreach ($requests as $request) {
                $originals[$request->id] = $request;
                $path = (string) parse_url($request->url, PHP_URL_PATH);
                $query = parse_url($request->url, PHP_URL_QUERY);
                yield new Request($request->id, $request->method, $this->origin.$path.(is_string($query) ? '?'.$query : ''), $request->headers, $request->body, $request->timeoutMs);
            }
        })();
        $inner = $this->inner->send($redirected, $profile);

        return (static function () use ($inner, &$originals): \Generator {
            foreach ($inner as $id => $exchange) {
                $original = $originals[$id] ?? $exchange->request;
                unset($originals[$id]);
                yield $id => new Exchange($original, $exchange->response, $exchange->failure, $exchange->durationMs);
            }
        })();
    }

    public function close(?string $poolKey = null): void
    {
        $this->inner->close($poolKey);
    }

    public function registry(): CurlPoolRegistry
    {
        return $this->inner->registry();
    }
}
