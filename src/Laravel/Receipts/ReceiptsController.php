<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST {prefix}/receipts. The marker is the capability: no authentication by default (see CLIENT_CONTRACT.md for
 * adding your own middleware). 204 for recorded AND duplicate reports; one generic 422 for everything else; 429 when
 * a marker or an IP reports too often. The marker is never echoed, logged or put in a cache key.
 */
final class ReceiptsController
{
    public function __construct(private readonly ReceiptRecorder $recorder) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $reports = ReceiptRequestParser::parse($request, ReceiptsModule::maxBatch());
        if ($reports === null) {
            return self::rejected();
        }

        $limit = ReceiptsModule::perMarkerPerMinute();
        if ($limit > 0) {
            foreach ($reports as $report) {
                // Keyed by a one-way hash, never by the marker itself.
                $key = 'mobile-push:receipts:marker:'.substr(hash('sha256', $report->marker), 0, 32);
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    return response()->json(['message' => 'Too many reports.'], 429, ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
                }
                RateLimiter::hit($key, 60);
            }
        }

        $ok = true;
        foreach ($reports as $report) {
            $ok = $this->recorder->record($report)->isAcknowledged() && $ok;
        }

        return $ok ? response()->noContent() : self::rejected();
    }

    private static function rejected(): JsonResponse
    {
        return response()->json(['message' => 'The receipt was not accepted.'], 422);
    }
}
