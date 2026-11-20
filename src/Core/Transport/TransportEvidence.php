<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

/**
 * What a transport-level failure proves about the request (see docs/contracts/outcomes.md).
 */
enum TransportEvidence: string
{
    /** Proven: no application request reached the provider (DNS, connect or TLS failed before any request byte was written). */
    case NotSent = 'not_sent';

    /** The request may or may not have been processed. This is the default for every failure without proof. */
    case Unknown = 'unknown';
}
