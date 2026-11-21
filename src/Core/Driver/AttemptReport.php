<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\Decision;

/**
 * Diagnostics of one attempt, safe for any sink: `$request` / `$response` are the Redactor's representations
 * (no authorization header, device paths masked, request body reduced to its size, provider body redacted).
 *
 * payload() is the compiled payload for observers that log it (`log.payload = keys | full`): computed on demand,
 * already passed through the attempt's redactor (bearer, receipt marker and destination value removed). An observer
 * that does not call it costs nothing: no payload is decoded or copied.
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
        /** @var (\Closure(): ?array<array-key, mixed>)|null */
        private ?\Closure $payload = null,
    ) {}

    /**
     * The redacted compiled payload of this attempt (decoded JSON body), or null when there is none. Computed at
     * each call: an observer that needs it later keeps what it needs.
     *
     * @return array<array-key, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload === null ? null : ($this->payload)();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'binding' => $this->binding->toArray(),
            'attempt' => $this->attempt,
            'credentialGeneration' => $this->credentialGeneration,
            'decision' => $this->decision->status->value,
            'request' => $this->request,
            'response' => $this->response,
            'failure' => $this->failure,
            'durationMs' => $this->durationMs,
        ];
    }
}
