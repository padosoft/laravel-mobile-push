<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default guard of the Prometheus endpoint. The scraper sends `Authorization: Bearer <mobile-push.metrics.prometheus.token>`.
 *
 * With no token configured every request is refused (403): the endpoint is never public by accident. Making it public
 * is an explicit choice: set `mobile-push.metrics.prometheus.middleware` to your own list (for example `[]`).
 * The comparison is constant-time (hash_equals over SHA-256 digests, so the length does not leak either).
 */
final class RequireMetricsToken
{
    public function __construct(private readonly HealthSettings $settings) {}

    /** @param  \Closure(Request): Response  $next */
    public function handle(Request $request, \Closure $next): Response
    {
        $expected = $this->settings->prometheusToken;
        if ($expected === null || $expected === '') {
            return new \Illuminate\Http\Response('Metrics endpoint disabled: no token configured.', 403, ['Content-Type' => 'text/plain']);
        }

        $given = $request->bearerToken();
        if ($given === null || ! hash_equals(hash('sha256', $expected), hash('sha256', $given))) {
            return new \Illuminate\Http\Response('Unauthorized.', 401, ['Content-Type' => 'text/plain', 'WWW-Authenticate' => 'Bearer']);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
