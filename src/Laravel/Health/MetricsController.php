<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Http\Response;

/** Serves the Prometheus text. Protection is the route middleware (RequireMetricsToken by default). */
final class MetricsController
{
    public function __invoke(PrometheusExporter $exporter): Response
    {
        return new Response($exporter->render(), 200, [
            'Content-Type' => PrometheusExporter::CONTENT_TYPE,
            'Cache-Control' => 'no-store',
        ]);
    }
}
