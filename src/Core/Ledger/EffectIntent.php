<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\OperationKind;

/**
 * What the caller wants to happen: one message, for one binding, under one idempotency key.
 * The destinations are passed separately (lazily) to EffectStore::recordIntent().
 *
 * Defaults taken from a PushMessage when not given: replaceable, policy and the logical expiry.
 */
final readonly class EffectIntent
{
    public const MAX_KEY_LENGTH = 191;

    public function __construct(
        public BindingRef $binding,
        public Message $message,
        /** Idempotency key (Laravel notification id, caller key). Null: a fresh ULID, i.e. no deduplication. */
        public ?string $effectKey = null,
        public OperationKind $operation = OperationKind::Send,
        /** Null: PushMessage::$replaceable, false for any other message. */
        public ?bool $replaceable = null,
        /** Absolute logical expiry. Null: PushMessage::resolveExpiry() at recording time. */
        public ?\DateTimeImmutable $expiresAt = null,
        /** Sending policy bucket (consent, caps). Null: PushMessage::$policy. */
        public ?string $policy = null,
        public TrafficClass $trafficClass = TrafficClass::Transactional,
        public ?EffectOrigin $origin = null,
        public ?string $campaignId = null,
        /** Deduplication window in seconds. Null: LedgerSettings::$dedupeSeconds. */
        public ?int $dedupeSeconds = null,
    ) {
        if ($effectKey !== null && (trim($effectKey) === '' || strlen($effectKey) > self::MAX_KEY_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $effectKey) === 1)) {
            throw new ValidationException('An effect key must be 1-'.self::MAX_KEY_LENGTH.' printable characters.');
        }
        if ($campaignId !== null && ($campaignId === '' || strlen($campaignId) > 64)) {
            throw new ValidationException('A campaign id must be 1-64 characters.');
        }
        if ($dedupeSeconds !== null && $dedupeSeconds < 0) {
            throw new ValidationException('The deduplication window must not be negative.');
        }
    }

    public function isReplaceable(): bool
    {
        return $this->replaceable ?? ($this->message instanceof PushMessage && $this->message->replaceable);
    }

    public function resolvedPolicy(): ?string
    {
        return $this->policy ?? ($this->message instanceof PushMessage ? $this->message->policy : null);
    }

    public function resolvedExpiry(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->expiresAt ?? ($this->message instanceof PushMessage ? $this->message->resolveExpiry($now) : null);
    }
}
