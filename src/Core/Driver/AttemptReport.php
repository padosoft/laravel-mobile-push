<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\Decision;

/**
 * Diagnostics of one attempt, safe for any sink: `$request` / `$response` are the Redactor's representations
 * (no authorization header, device paths masked, request body reduced to its size, provider body redacted).
 */
final readonly class AttemptReport
{
    /**
     * @param  array<string, mixed>  $request  Redactor::request()
     * @param  array<string, mixed>|null  $response  Redactor::response(), null when the transport failed
     * @param  array{evidence: string, errno: int, message: string, request_written: bool}|null  $failure  redacted transport failure
     */
    public function __construct(
        public BindingRef $binding,
        public SendItem $item,
        /** 1-based number of this attempt within the destination's budget. */
        public int $attempt,
        public int $credentialGeneration,
        public string $poolKey,
        public Decision $decision,
        public array $request,
        public ?array $response,
        public ?array $failure,
        public float $durationMs,
    ) {}
}
