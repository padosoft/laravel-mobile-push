<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Immutable, already-safe description of one send outcome. Built by LogRecordFactory, which does all redaction and
 * masking: no field holds a device token, bearer, receipt marker or key. The destination is only its keyed index
 * and masked form.
 */
final readonly class LogRecord
{
    public function __construct(
        public string $scope,
        public string $provider,
        public string $bindingName,
        public string $appId,
        public string $environment,
        /** Operation kind, e.g. "send", "live_activity", "broadcast" (bounded vocabulary). */
        public string $operation,
        public ?string $pushType,
        public ?string $trafficClass,
        public string $destinationKind,
        /** TokenIndex value (keyed HMAC). */
        public string $destinationIndex,
        /** TokenMasker form. */
        public string $destinationMasked,
        public SendStatus $status,
        public ReasonCode $reasonCode,
        /** Provider text, sanitized and redacted. */
        public ?string $reason,
        public ?int $httpStatus,
        public ?string $providerMessageId,
        public int $attempts,
        public ?int $durationMs,
        public ?int $payloadBytes,
        public PayloadLevel $payloadLevel,
        /** @var array<array-key, mixed>|null key names (level keys) or the redacted payload (level full) */
        public ?array $payload,
        public ?string $effectId,
        public ?string $deliveryId,
        public ?string $campaignId,
        public ?string $originType,
        public ?string $originId,
        public ?string $notification,
        public \DateTimeImmutable $at,
    ) {}

    /** Accepted, simulated and validated are routine successes; everything else is worth keeping. */
    public function isRoutine(): bool
    {
        return in_array($this->status, [SendStatus::Accepted, SendStatus::Simulated, SendStatus::Validated], true);
    }

    /**
     * Snake-case map, nulls omitted (structured log context).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'scope' => $this->scope,
            'provider' => $this->provider,
            'binding' => $this->bindingName,
            'app_id' => $this->appId,
            'environment' => $this->environment,
            'operation' => $this->operation,
            'push_type' => $this->pushType,
            'traffic_class' => $this->trafficClass,
            'destination_kind' => $this->destinationKind,
            'destination_index' => $this->destinationIndex,
            'destination_masked' => $this->destinationMasked,
            'status' => $this->status->value,
            'reason_code' => $this->reasonCode->value,
            'reason' => $this->reason,
            'http_status' => $this->httpStatus,
            'provider_message_id' => $this->providerMessageId,
            'attempts' => $this->attempts,
            'duration_ms' => $this->durationMs,
            'payload_bytes' => $this->payloadBytes,
            'payload_level' => $this->payloadLevel->value,
            'payload' => $this->payload,
            'effect_id' => $this->effectId,
            'delivery_id' => $this->deliveryId,
            'campaign_id' => $this->campaignId,
            'origin_type' => $this->originType,
            'origin_id' => $this->originId,
            'notification' => $this->notification,
            'at' => $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
