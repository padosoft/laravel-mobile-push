<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Psr\Clock\ClockInterface;

/**
 * Builds LogRecords. ALL redaction and masking happens here, so sinks never see a raw value.
 *
 * Context keys (all optional): operation, push_type, traffic_class (bounded vocabulary: [a-z0-9_.-]{1,32}, else
 * "other"), duration_ms, payload_bytes, payload (array, shown according to the payload level), effect_id,
 * campaign_id, origin_type, origin_id, notification, at (DateTimeInterface), secrets (list<string>: bearer values,
 * receipt markers, raw tokens that must never appear anywhere).
 */
final readonly class LogRecordFactory
{
    private const MAX_PAYLOAD_KEYS = 200;

    private const MAX_PAYLOAD_JSON_BYTES = 8192;

    public function __construct(
        private Redactor $redactor,
        private TokenIndex $index,
        private ClockInterface $clock,
        private PayloadLevel $payloadLevel = PayloadLevel::None,
    ) {}

    /** How much of the payload the records carry (`mobile-push.log.payload`). */
    public function payloadLevel(): PayloadLevel
    {
        return $this->payloadLevel;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function make(BindingRef $binding, SendResult $result, array $context = []): LogRecord
    {
        $destination = $result->destination;

        $secrets = [$destination->value()];
        $given = $context['secrets'] ?? [];
        if (is_array($given)) {
            foreach ($given as $secret) {
                if (is_string($secret) && $secret !== '') {
                    $secrets[] = $secret;
                }
            }
        }
        $redactor = $this->redactor->withSecrets($secrets);

        $at = $context['at'] ?? null;
        $at = $at instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($at) : $this->clock->now();

        $raw = $result->reason->rawReason;

        return new LogRecord(
            scope: self::text($redactor, $binding->scope, 64) ?? '',
            provider: $binding->provider->value,
            bindingName: self::text($redactor, $binding->name, 128) ?? '',
            appId: self::text($redactor, $binding->appId, 255) ?? '',
            environment: $binding->environment->value,
            operation: self::vocabulary($context['operation'] ?? null) ?? 'send',
            pushType: self::vocabulary($context['push_type'] ?? null),
            trafficClass: self::vocabulary($context['traffic_class'] ?? null),
            destinationKind: $destination->kind()->value,
            destinationIndex: $this->index->index($destination, $binding->scope),
            destinationMasked: TokenMasker::mask($destination->value()),
            status: $result->status,
            reasonCode: $result->reason->code,
            reason: $raw === null ? null : self::cut($redactor->providerMessage($raw), 191),
            httpStatus: $result->httpStatus,
            providerMessageId: self::text($redactor, $result->providerMessageId, 255),
            attempts: $result->attempts,
            durationMs: self::int($context['duration_ms'] ?? null),
            payloadBytes: self::int($context['payload_bytes'] ?? null),
            payloadLevel: $this->payloadLevel,
            payload: $this->payloadView($redactor, $context['payload'] ?? null),
            effectId: self::text($redactor, self::string($context['effect_id'] ?? null), 64),
            deliveryId: self::text($redactor, $result->deliveryId, 64),
            campaignId: self::text($redactor, self::string($context['campaign_id'] ?? null), 64),
            originType: self::text($redactor, self::string($context['origin_type'] ?? null), 191),
            originId: self::text($redactor, self::string($context['origin_id'] ?? null), 64),
            notification: self::text($redactor, self::string($context['notification'] ?? null), 191),
            at: $at->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    /** @return array<array-key, mixed>|null */
    private function payloadView(Redactor $redactor, mixed $payload): ?array
    {
        if ($this->payloadLevel === PayloadLevel::None || ! is_array($payload)) {
            return null;
        }

        if ($this->payloadLevel === PayloadLevel::Keys) {
            $keys = [];
            self::collectKeys($payload, '', $keys);

            return array_map(static fn (string $key): string => self::cut($redactor->string($key), 128), $keys);
        }

        $safe = $redactor->value($payload);
        if (! is_array($safe)) {
            return null;
        }

        $json = json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false && strlen($json) > self::MAX_PAYLOAD_JSON_BYTES) {
            return ['truncated' => true, 'bytes' => strlen($json)];
        }

        return $safe;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $out
     */
    private static function collectKeys(array $data, string $prefix, array &$out): void
    {
        foreach ($data as $key => $value) {
            if (count($out) >= self::MAX_PAYLOAD_KEYS) {
                return;
            }

            // List indexes carry no information: aps.alerts.0.title -> aps.alerts.title.
            $name = is_int($key) ? $prefix : ($prefix === '' ? (string) $key : $prefix.'.'.$key);

            if (is_array($value) && $value !== []) {
                self::collectKeys($value, $name, $out);
            } elseif ($name !== '' && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
    }

    private static function text(Redactor $redactor, ?string $value, int $max): ?string
    {
        return $value === null ? null : self::cut($redactor->string($value), $max);
    }

    private static function cut(string $value, int $max): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $value), 0, $max);
    }

    private static function vocabulary(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $value = strtolower($value);

        return preg_match('/^[a-z0-9_.-]{1,32}$/', $value) === 1 ? $value : 'other';
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || $value instanceof \Stringable ? (string) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? max(0, (int) $value) : null;
    }
}
