<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;

/**
 * The transport behind the intent fake. The fake driver builds placeholder requests that carry nothing; this only
 * hands back an answer so the pipeline keeps its shape. The scripted outcome comes from the fake's classifier.
 *
 * @internal
 */
final class AcceptingTransport implements Transport
{
    public int $requests = 0;

    public function send(iterable $requests, ConnectionProfile $profile): \Generator
    {
        foreach ($requests as $request) {
            $this->requests++;

            yield $request->id => Exchange::responded($request, new Response(200));
        }
    }

    public function close(?string $poolKey = null): void {}
}
